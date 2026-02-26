export type SshKey = {
  id: number
  name: string
  publicKey: string
  fingerprint: string
  createdAt: string
}

export type CreateSshKeyPayload = {
  name: string
  public_key: string
  private_key: string
}
