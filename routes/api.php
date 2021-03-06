<?php

use Illuminate\Http\Request;

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


Route::prefix('user')->group(function() {
    Route::post('/send-message', 'ApiController@sendMessage');
    Route::post('/get-messages', 'ApiController@getMessages');
    Route::post('/reply-message', 'ApiController@replyMessage');
    Route::post('/get-conversations', 'ApiController@getConversations');
});
