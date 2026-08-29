<?php

namespace App\Http\Controllers\Api;

use App\Enums\UsageMetric;
use App\Http\Controllers\Controller;
use ClickHouseDB\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UsageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d',
            // Constrained to the catalogue, not free text. `value` is one shared column whose
            // unit lives in the metric name, so an unrecognised name used to answer with an empty
            // result — which, for a caller building an invoice out of this, reads as zero rather
            // than as a typo. `origin_bytes` is left out on purpose: it is booked under account 0,
            // which no account-scoped read can ever be, so accepting it would only ever answer
            // empty for a different reason.
            'metric' => ['nullable', 'string', Rule::in(UsageMetric::account())],
            // The same width the column and the video's own `external_user_id` are validated to,
            // so a value this endpoint accepts is one an upload could actually have stored.
            'external_user_id' => 'nullable|string|max:255',
        ]);

        $where = ['user_id = {user_id:UInt32}', 'date >= {from:Date}', 'date <= {to:Date}'];
        $params = [
            'user_id' => $request->user()->accountId(),
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
