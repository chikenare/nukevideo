<?php

namespace App\Http\Controllers\Api;

use App\Data\SshKey\StoreSshKeyData;
use App\Data\SshKeyData;
use App\Http\Controllers\Controller;
use App\Models\SshKey;
use App\Services\SshKeyService;

class SshKeyController extends Controller
{
    public function __construct(protected SshKeyService $sshKeyService) {}

    public function index()
    {
        return response()->json(['data' => SshKey::all()->map(fn ($k) => SshKeyData::fromModel($k))->all()]);
    }

    public function store(StoreSshKeyData $data)
    {
        $key = $this->sshKeyService->createKey($data->toDatabase());

        return response()->json(['data' => SshKeyData::fromModel($key)]);
    }

    public function show(string $id)
    {
        return response()->json(['data' => SshKeyData::fromModel(SshKey::findOrFail($id))]);
    }

    public function destroy(string $id)
    {
        $key = SshKey::findOrFail($id);

        // nodes.ssh_key_id nulls on delete, and a node without a key can't be deployed to or
        // torn down — refuse while anything still authenticates with it.
        if ($key->nodes()->exists()) {
            abort(422, 'This key is assigned to one or more nodes. Reassign them first.');
        }

        $key->delete();

        return response()->json(['message' => 'SSH key deleted']);
    }
}
