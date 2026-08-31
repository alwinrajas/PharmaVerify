<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Serving the web application
|--------------------------------------------------------------------------
|
| In a client installation the compiled front end is copied into public/ and
| served from this same origin. That is deliberate: the API client asks for a
| relative "/api", so the application follows whatever address it is reached
| on and needs no per-site rebuild. A pharmacy that changes the PC's address
| changes nothing here.
|
| React Router owns every path below. Without the catch-all, opening
| /verification directly — or simply pressing refresh on it — asks the server
| for a file that does not exist and returns 404 on a page the operator was
| already looking at.
|
*/

/**
 * The built application if it has been installed, the placeholder otherwise.
 *
 * A closure rather than a named function: this file is loaded more than once
 * in a test run, and a global declaration cannot be made twice.
 *
 * Falls back rather than failing so a development checkout — where nothing has
 * been built into public/ yet — still serves something explicable instead of a
 * missing-file error.
 */
$spa = function () {
    $index = public_path('index.html');

    return file_exists($index)
        ? response()->file($index)
        : view('welcome');
};

Route::get('/', $spa);

// Everything that is not an API call, the health check, or a real file on
// disk. The API is registered separately and is matched first, so it is not
// shadowed by this.
Route::get('/{path}', $spa)->where('path', '^(?!api|up|storage).*$');
