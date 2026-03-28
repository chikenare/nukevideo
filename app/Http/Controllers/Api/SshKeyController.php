<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SshKey\StoreSshKeyRequest;
use App\Http\Resources\SshKeyResource;
use App\Models\SshKey;
use App\Services\SshKeyService;

class SshKeyController extends Controller
{
    public function __construct(protected SshKeyService $sshKeyService) {}

    public function index()
    {
        return SshKeyResource::collection(SshKey::all());
    }

    public function store(StoreSshKeyRequest $request)
    {
        $key = $this->sshKeyService->createKey($request->validated());

        return new SshKeyResource($key);
    }

    public function show(string $id)
    {
        return new SshKeyResource(SshKey::findOrFail($id));
    }

    public function destroy(string $id)
    {
        $key = SshKey::findOrFail($id);
        $key->delete();

        return response()->json(['message' => 'SSH key deleted']);
    }
}
