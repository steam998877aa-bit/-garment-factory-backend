<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    // Gives every controller $this->authorize(), which aborts with 403 when a
    // policy denies the action.
    use AuthorizesRequests;
}
