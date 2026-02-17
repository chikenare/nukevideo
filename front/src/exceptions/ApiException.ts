export class ApiException extends Error {
  public status: number;
  public error: string;

  constructor(message: string, status: number, error: string) {
    super(message);
    this.name = 'ApiException';
    this.status = status;
    this.error = error;

  }
}
