import { ApiException } from './ApiException';

export class ValidationException extends ApiException {
  public errors: Record<string, string[]>;

  constructor(message: string, errors: Record<string, string[]> = {}, rawError: string = 'Unprocessable Entity') {
    super(message, 422, rawError);

    this.name = 'ValidationException';
    this.errors = errors;

    Object.setPrototypeOf(this, ValidationException.prototype);
  }
}
