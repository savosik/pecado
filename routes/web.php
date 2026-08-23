<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\User\PasswordResetController;
use App\Http\Controllers\User\SocialAuthController;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// ──────────────────────────────────────────────
// Guest routes (only for unauthenticated users)
// ──────────────────────────────────────────────
Route::middleware('guest')->group(function () {
    // Login
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);

    // Registration
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register']);

    // Password Reset
    Route::get('/forgot-password', [PasswordResetController::class, 'showForgotPassword'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'showResetPassword'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'resetPassword'])->name('password.update');

    // OAuth
    Route::get('/auth/{provider}/redirect', [SocialAuthController::class, 'redirect'])->name('auth.social.redirect');
    Route::get('/auth/{provider}/callback', [SocialAuthController::class, 'callback'])->name('auth.social.callback');
});

// Подписной ICS-фид задач CRM: Google и Яндекс Календарь ходят по ссылке
// без сессии, доступ охраняет только 64-символьный токен (отзывается перевыпуском).
Route::get('/crm/tasks/feed/{token}.ics', [\App\Http\Controllers\Crm\CalendarFeedController::class, 'feed'])
    ->where('token', '[A-Za-z0-9]{64}')
    ->middleware('throttle:60,1')
    ->name('crm.tasks.feed');

// ──────────────────────────────────────────────
// Authenticated routes
// ──────────────────────────────────────────────
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    // Выход из режима «просмотр от имени клиента». Не в routes/crm.php: сессия
    // сейчас клиентская, и EnsureUserIsCrm увёл бы менеджера на главную вместо
    // возврата в свою учётку. Сам режим включается в /crm/clients/{id}/impersonate.
    Route::post('/impersonation/stop', [\App\Http\Controllers\Crm\ImpersonationController::class, 'stop'])
        ->name('impersonation.stop');

    // Onboarding
    Route::get('/onboarding', [\App\Http\Controllers\User\OnboardingController::class, 'show'])->name('onboarding');
    Route::post('/onboarding', [\App\Http\Controllers\User\OnboardingController::class, 'store'])->name('onboarding.store');
    Route::post('/onboarding/personal', [\App\Http\Controllers\User\OnboardingController::class, 'savePersonal'])->name('onboarding.personal');
    Route::post('/onboarding/skip', [\App\Http\Controllers\User\OnboardingController::class, 'skip'])->name('onboarding.skip');
});

// ──────────────────────────────────────────────
// User-facing routes (Home, Products, Cabinet)
// ──────────────────────────────────────────────
require __DIR__.'/user.php';

// Sitemap
Route::get('/sitemap.xml', function () {
    $path = public_path('sitemap.xml');
    if (! file_exists($path)) {
        Artisan::call('sitemap:generate');
    }

    return response()->file($path, ['Content-Type' => 'application/xml']);
})->name('sitemap');

// Публичный YML-фид Яндекс.Маркета (розничные цены, товары активных категорий).
// Генерируется по расписанию: feed:build-yandex (см. routes/console.php).
Route::get('/feed/yandex-market.yml', [\App\Http\Controllers\YandexMarketFeedController::class, 'show'])
    ->name('feed.yandex-market');

// Старый путь /catalog → постоянный редирект на /products. Сохраняется,
// чтобы битые ссылки в AI-генерируемом контенте и старые SEO-ссылки
// уводили на актуальный каталог, а не на 404.
Route::permanentRedirect('/catalog', '/products');
Route::get('/catalog/{any}', fn () => redirect('/products', 301))->where('any', '.*');

// Просмотр профиля пользователя по секретному токену (для менеджеров без доступа в админку)
Route::get('/preview/user/{token}', [\App\Http\Controllers\UserPreviewController::class, 'show'])->name('user.preview');

// AI Generation
Route::post('/ai/generate', [App\Http\Controllers\Admin\AiController::class, 'generate'])->name('admin.ai.generate');

// Blueprint типографики контента (утилитарная страница для компиляции Tailwind-классов)
Route::get('/content-blueprint', fn () => Inertia::render('ContentBlueprint'))->name('content.blueprint');

// Публичная ссылка для скачивания выгрузки товаров
Route::get('/export/{hash}', [\App\Http\Controllers\ProductExportDownloadController::class, 'download'])->name('export.download');

Route::post('/api/products/{slug}/pros-cons/generate', [\App\Http\Controllers\User\ProductProsConsController::class, 'generate'])->middleware('auth')->name('products.pros_cons.generate');

Route::get('/health', \Spatie\Health\Http\Controllers\HealthCheckJsonResultsController::class)->name('health');

Route::post('/bug-report', [\App\Http\Controllers\BugReportController::class, 'store'])->name('bug-report.store');

// ──────────────────────────────────────────────
// DaData proxy (бесплатный «Лёгкий» тариф)
// ──────────────────────────────────────────────
Route::middleware(['auth', 'throttle:60,1'])->prefix('api/dadata')->group(function () {
    Route::post('/suggest/party', [\App\Http\Controllers\Api\DaDataController::class, 'suggestParty'])
        ->name('dadata.suggest.party');
    Route::post('/findById/party', [\App\Http\Controllers\Api\DaDataController::class, 'findPartyByInn'])
        ->name('dadata.findById.party');
    Route::post('/suggest/bank', [\App\Http\Controllers\Api\DaDataController::class, 'suggestBank'])
        ->name('dadata.suggest.bank');
    Route::post('/findById/bank', [\App\Http\Controllers\Api\DaDataController::class, 'findBankByBik'])
        ->name('dadata.findById.bank');
    Route::post('/suggest/address', [\App\Http\Controllers\Api\DaDataController::class, 'suggestAddress'])
        ->name('dadata.suggest.address');
    Route::post('/geolocate/address', [\App\Http\Controllers\Api\DaDataController::class, 'geolocateAddress'])
        ->name('dadata.geolocate.address');
});

// Email-подсказки нужны на странице регистрации (без auth).
// Защищаем строгим throttle на IP.
Route::middleware('throttle:30,1')->prefix('api/dadata')->group(function () {
    Route::post('/suggest/email', [\App\Http\Controllers\Api\DaDataController::class, 'suggestEmail'])
        ->name('dadata.suggest.email');
});

/*
 * Отслеживание прочтения писем: пиксель и редирект по ссылке.
 *
 * Публичные и короткие — их дёргает почтовый клиент получателя. Вне групп
 * с авторизацией сознательно: у адресата письма никакой сессии нет.
 *
 * Без расширения `.gif` в адресе, хотя картинка отдаётся именно gif: nginx ловит
 * такие адреса своим правилом для статики и до PHP запрос не доходит — пиксель
 * молча отдавал бы 404. Почтовому клиенту важен Content-Type, а не вид ссылки.
 */
Route::get('/e/o/{token}', [\App\Http\Controllers\MailTrackingController::class, 'open'])
    ->name('mail.track.open')
    ->where('token', '[A-Za-z0-9]{10,40}');

Route::get('/e/c/{token}', [\App\Http\Controllers\MailTrackingController::class, 'click'])
    ->middleware('signed')
    ->name('mail.track.click')
    ->where('token', '[A-Za-z0-9]{10,40}');
