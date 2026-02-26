<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SshKeyResource;
use App\Models\SshKey;
use Illuminate\Http\Request;

class SshKeyController extends Controller
{
    public function index()
    {
        return SshKeyResource::collection(SshKey::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:ssh_keys,name',
            'public_key' => 'required|string',
            'private_key' => 'required|string',
        ]);

        $validated['fingerprint'] = $this->generateFingerprint($validated['public_key']);

        $key = SshKey::create($validated);

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

    private function generateFingerprint(string $publicKey): string
    {
        $parts = explode(' ', trim($publicKey));
        $keyData = base64_decode($parts[1] ?? $parts[0]);

        return implode(':', str_split(md5($keyData), 2));
    }
}
