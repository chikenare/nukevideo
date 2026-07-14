<?php

namespace App\Http\Controllers;

use App\Data\UserData;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __invoke(Request $request)
    {
        return response()->json(['data' => UserData::fromModel($request->user()->load('projects.tokens'))]);
    }
}
