<?php

use App\Http\Controllers\OutreachApiController;
use App\Http\Middleware\VerifyCrmApiToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});

/*
| Outreach write API — token-gated (CRM_API_TOKEN), rate-limited. Lets the outreach
| agent upsert leads and log touches without touching MySQL or markdown. See
| App\Http\Controllers\OutreachApiController.
*/
Route::prefix('outreach')
    ->middleware([VerifyCrmApiToken::class, 'throttle:60,1'])
    ->group(function () {
        Route::post('leads/upsert', [OutreachApiController::class, 'upsertLead']);
        Route::post('touches', [OutreachApiController::class, 'logTouch']);
    });
