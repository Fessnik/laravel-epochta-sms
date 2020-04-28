<?php

namespace Fomvasss\EpochtaService;

use Fomvasss\EpochtaService\Events\AfterSendingSmsEvent;
use Fomvasss\EpochtaService\Events\BeforeSendingSmsEvent;
use Fomvasss\EpochtaService\Models\EpochtaSms;

/**
 * Class State
 *
 * @package \Fomvasss\EpochtaService
 */
class Stat extends \Stat
{
    use CheckResult;

    /**
     * Создать и отправить смс.
     *
     * @param $sender
     * @param $body
     * @param $phone
     * @param null $datetime
     * @param int $smsLifetime
     * @return bool|mixed
     */
    public function sendSMS($attributes)
    {
        $attributes['sender'] = $this->checkSender($attributes['sender']);
        $attributes['phone'] = $this->clearPhone($attributes['phone']);
        $attributes['lifetime'] = $this->getLifeTime($attributes['lifetime']);

        $result = $this->dispatch($attributes);

        return $this->checkResult($result) ?? [];
    }

    /**
     * Получить информацию об отправленной смс/компании.
     *
     * @param $id
     * @return bool|mixed
     */
    public function getCampaignInfo($id)
    {
        $res = parent::getCampaignInfo($id);

        if ($this->checkResult($res)) {
            // Событие после успешного получения статуса смс
            if (config('epochta-sms.use_db', false) && ($sms = EpochtaSms::where('sms_id', $id)->first())) {
                $this->smsDbUpdate($sms, $res);
            }

            return $res;
        }

        return [];
    }

    /**
     * Обновить статус смс в БД.
     *
     * @param $sms
     * @param $campaignInfoResult
     * @return mixed
     */
    public function smsDbUpdate(EpochtaSms $sms, $campaignInfoResult)
    {
        if ($campaignInfoResult && isset($campaignInfoResult['result']['sent'])) {
            $sms->sms_sent_status = $campaignInfoResult['result']['sent']; // состояние epochta отправки смс, 1 - отправлено получателю
            if ($campaignInfoResult['result']['delivered'] == 1) {
                $sms->sms_delivered_status = 1; // доставлено смс получателю
            }
            if ($campaignInfoResult['result']['not_delivered'] == 1) {
                $sms->sms_delivered_status = 2; // недоставлено смс получателю (и уже не будет!)
            }
            $sms->dispatch_status = $campaignInfoResult['result']['status'];  // состояние рассылки
            $sms->save();
        }

        return $sms;
    }

    /**
     * Получить (и обновить) статусы всех не обновленных (и не старых) смс.
     *
     * @param int|null $isOlderAfter
     * @return bool
     */
    public function smsDbUpdateStatuses(int $isOlderAfter = 0)
    {
        $isOldAfter = $isOlderAfter ?: config('epochta-sms.is_old_after', 360);

        EpochtaSms::whereNotNull('sms_id')
            ->where('sms_delivered_status', 0)
            ->where('created_at', '>', \Carbon\Carbon::now()->addHour(-$isOldAfter))
            ->where('updated_at', '<', \Carbon\Carbon::now()->addMinute(-1))
            ->chunk(100, function ($messages) {
                foreach ($messages as $sms) {
                    $this->getCampaignInfo($sms->sms_id);
                }
            });

        return true;
    }

    /**
     *
     * Повтороно отправить все смс в которых нет статуса "Доставлено",
     * которые еще не отправлялись повторно
     * котории созданы не позднее чем $maxMinutes мин. назад
     * котории созданы не ранее чем $minMinutes мин. назад
     * и которые имеют меньше равно $maxAttempt попыток отправки
     *
     * @param int $minMinutes
     * @param int $maxMinutes
     * @param int $maxAttempt
     * @return bool
     */
    public function smsDbResendUndelivered(int $minMinutes = 0, int $maxMinutes = 0, int $maxAttempt = 0)
    {
        $minMinutes = $minMinutes ?: config('epochta-sms.attempts_transfer.min_minutes', 4);
        $maxMinutes = $maxMinutes ?: config('epochta-sms.attempts_transfer.max_minutes', 7);
        $maxAttempt = $maxAttempt ?: config('epochta-sms.attempts_transfer.max_attempt', 2);

        EpochtaSms::whereNull('resend_sms_id')
            ->where('attempt', '<=', $maxAttempt)
            ->where('sms_delivered_status', '<>', 1)
            ->where('created_at', '>', \Carbon\Carbon::now()->addMinute(-$maxMinutes))
            ->where('created_at', '<', \Carbon\Carbon::now()->addMinute(-$minMinutes))
            ->chunk(100, function ($messages) {
                foreach ($messages as $sms) {
                    $this->smsDbResend($sms);
                }
            });

        return true;
    }

    /**
     * Повторно отправить смс - создать новую запись в БД.
     *
     * @param \Fomvasss\EpochtaService\Models\EpochtaSms $sms
     * @return mixed
     */
    public function smsDbResend(EpochtaSms $sms)
    {
        $result = $this->dispatch([
            'sender' => $sms->sender,
            'phone' => $sms->phone,
            'body' => $sms->body,
            'datetime' => $sms->datetime,
            'lifetime' => $sms->smsLifetime,
            'attempt' => ++$sms->attempt,
        ]);

        if (! empty($result['result']['id'])) {
            $sms->resend_sms_id = $result['result']['id'];
        }
        $sms->save();

        return $result;
    }

    /**
     * Получить "удобочитаемый" статус для вывода.
     * https://www.epochta.com.ua/products/sms/v3.php
     *
     * @param \Fomvasss\EpochtaService\Models\EpochtaSms $sms
     * @return string
     */
    public function getGeneralStatus(EpochtaSms $sms)
    {
        $stat = 0;
        if ($sms->sms_delivered_status == 1) {
            $stat = 1;
        } elseif ($sms->sms_delivered_status == 2) {
            $stat = 2;
        } elseif ($sms->sms_sent_status == 1) {
            $stat = 3;
        } elseif ($sms->sms_id) {
            $stat = 4;
        }

        return config('epochta-sms.human_statuses')[$stat] ?? 'Error';
    }

    /**
     * Отправка смс на сервис и сохранение в бд.
     *
     * @param $attributes
     * @return mixed
     */
    protected function dispatch($attributes)
    {
        event(new BeforeSendingSmsEvent($attributes));

        $smsModel = null;
        if ($useDb = config('epochta-sms.use_db', false)) {
            $smsModel = $this->smsDbSaveNew($attributes);
        }

        $result = parent::sendSMS($attributes);

        event(new AfterSendingSmsEvent($attributes, $result, $smsModel));

        if (!empty($smsModel)) {
            $this->smsDbUpdateAfterSend($smsModel, $result);
        }

        return $result;
    }

    /**
     * Сохранить новую смс.
     *
     * @param $attributes
     * @return mixed
     */
    protected function smsDbSaveNew($attributes)
    {
        return EpochtaSms::create($attributes);
    }

    /**
     * Обновить данные смс после отправки.
     *
     * @param $sendingResult
     * @param $smsModel
     * @return mixed
     */
    protected function smsDbUpdateAfterSend($smsModel, $sendingResult)
    {
        if (! empty($sendingResult['result']['id'])) {
            $smsModel->sms_id = $sendingResult['result']['id']; // ид смс на сервисе epochta
            $smsModel->save();
        }

        return $smsModel;
    }


    /**
     * @param $str
     * @return mixed
     */
    protected function clearPhone($str)
    {
        return preg_replace('/[^0-9]/si', '', $str);
    }

    /**
     * @param string|null $senderName
     * @return bool|string
     */
    protected function checkSender(string $senderName = null)
    {
        return substr($senderName ?? config('epochta-sms.sender', 'Sender'), 0, 11);
    }

    /**
     * @param null $smsLifetime
     * @return \Illuminate\Config\Repository|mixed|null
     */
    protected function getLifeTime($smsLifetime = null)
    {
        return $smsLifetime ?? config('epochta-sms.sms_lifetime', 0);
    }
}
