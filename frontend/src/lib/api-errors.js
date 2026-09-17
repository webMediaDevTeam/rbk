export function getApiErrorMessage(error) {
  return error?.response?.data?.message ?? 'Une erreur est survenue.'
}

export function getApiFieldErrors(error) {
  return error?.response?.data?.errors ?? {}
}