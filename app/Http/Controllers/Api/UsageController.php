<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use ClickHouseDB\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UsageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d',
            'metric' => 'nullable|string',
            'external_user_id' => 'nullable|string',
        ]);

        $where = ['user_id = {user_id:UInt32}', 'date >= {from:Date}', 'date <= {to:Date}'];
        $params = [
            'user_id' => $request->user()->id,
            'from' => $request->input('from'),
            'to' => $request->input('to'),
        ];

        if ($request->filled('metric')) {
            $where[] = 'metric = {metric:String}';
            $params['metric'] = $request->input('metric');
        }

        if ($request->filled('external_user_id')) {
            $where[] = 'external_user_id = {external_user_id:String}';
            $params['external_user_id'] = $request->input('external_user_id');
        }

        $whereClause = implode(' AND ', $where);

        $rows = app(Client::class)->select(
            "SELECT metric, external_user_id, sum(value) AS value, date
             FROM usage
             WHERE {$whereClause}
             GROUP BY metric, external_user_id, date
             ORDER BY date",
            $params
        )->rows();

        return response()->json(['data' => $rows]);
    }
}
