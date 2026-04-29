export type SshKey = {
  id: number
  name: string
  publicKey: string
  fingerprint: string
  createdAt: string
}

export type CreateSshKeyPayload = {
  name: string
  publicKey: string
  privateKey: string
}
