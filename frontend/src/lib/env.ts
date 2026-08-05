export function apiBaseUrl(): string {
  return process.env.API_INTERNAL_URL ?? "http://localhost:8080/api/v1";
}

export function appUrl(): string {
  return process.env.NEXT_PUBLIC_APP_URL ?? "http://localhost:3000";
}
