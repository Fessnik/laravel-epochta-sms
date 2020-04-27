# Laravel Epochta SMS

Пакет для отправки СМС с помощью сервиса [epochta](https://www.epochta.ru)

## Установка
Запустить 
```bash
composer require "fomvasss/laravel-epochta-sms"
```
---
### Для Laravel < v5.5 !

Добавить в ServiceProvider в массив providers (файл config/app.php):

```php
Fomvasss\EpochtaService\SmsServiceProvider::class,
```
И для использования фасада, добавить в массив aliases строку:

```php
'Sms' => Fessnik\EpochtaService\Facade\Sms::class,
```
---

Публикация конфигов:

```bash
php artisan  vendor:publish --provider="Fessnik\EpochtaService\SmsServiceProvider" --tag=epochta-sms-config
```
Если вы планируете сохранять информацию об отправленных СМС а также их статусы, добавьте миграцию:
```bash
php artisan migrate --path=vendor/fessnik/laravel-epochta-sms/database/migrations
``` 
а если передумаете, то:
```bash
php artisan migrate:rollback --path=vendor/fessnik/laravel-epochta-sms/database/migrations
```

## Использование

! Исли установлено в конфигу `use_db == true` - следующии методы пишут информацию в таблицу базы данных.

### Использование Sms класс

```php
<?php
use Fomvasss\EpochtaService\Sms;

class MyClass
{
	protected $sms;
	
	public function __construct(Sms $sms)
	{
		$this->sms = $sms;
	}
	
	public function run()
	{
		$r = $this->sms->account()->getUserBalance('RUB'); // получить баланс счета - array['balance_currency', ...]
		$r = $this->sms->stat()->sendSms('test sms text', '380656565656'); // отправить
		$r = $this->sms->stat()->sendSms('Text sms', '380656565656', 'Sender-name', '2017-10-31 16:08:00', '6'); // отправить
		$r = $this->sms->stat()->getCampaignInfo(96972041); // получить инфо об отправке
		
		// Need db table
		$sms = EpochtaSms::find(2);
		$r = $this->sms->stat()->smsDbResend($sms); // отправить повторно, при этом записать в поле `resend_sms_id` текущей модели, значиние новой `sms_id`
		$r = $this->sms->stat()->getGeneralStatus($sms); // пулучить статус в виде строки с конфига
		
		$r = $this->sms->stat()->smsDbUpdateStatuses(); // обновить все статусы, смс в которых еще нет конечного статуса
		$r = $this->sms->stat()->smsDbResendUndelivered(5, 10, 3); // отправить повторно все не доставленные
	}
}
```

### Использование Sms фасада
```php
<?php
    Sms::account()->getUserBalance();
    Sms::stat()->sendSms('test sms text', '380656565656');
    Sms::stat()->sendSms('test sms text', '380656565656', 'SenderTest2', '2017-10-31 16:08:00', '6');
    Sms::stat()->getCampaignInfo(96972041);
    Sms::stat()->getAllCampaignInfoFromDb();
```

Например, вы можете использовать метод для обновления статусов смс `getAllCampaignInfoFromDb()` в CRON задаче (app/Console/Kernel.php):

```php
<?php
    $schedule->call(function () {
        if (env('SMS_UPDATE_STATUS')) {
			\Sms::stat()->smsDbUpdateStatuses();
        }
    })->cron('* * * * * *');
```

### Использование событий:

Перед отправкой смс (поля: `attributes` - массив данных смс)
```
\Fomvasss\EpochtaService\Events\BeforeSendingSmsEvent
``` 

После отправки смс (поля: `attributes` - массив данных смс, `sendingResult` - результат отправки, `model` - модель сохраненной смс)
```
\Fomvasss\EpochtaService\Events\AfterSendingSmsEvent
```

## Больше информации и API смотрите в официальной документации:

- https://www.epochta.com.ua/products/sms/v3.php 
- https://www.epochta.ru/products/sms/php-30-example.php
