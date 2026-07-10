<?php

namespace App\Http\Controllers;

use App\Services\StreamManagementService;
use Illuminate\Http\Request;

class StreamController extends Controller
{
    public function __construct(protected StreamManagementService $streamService) {}

    public function destroy(Request $request, string $ulid)
    {
        $this->streamService->destroy($ulid, $request->user());

        return response()->json([
            'message' => 'Stream deleted successfully',
        ]);
    }
}
