export function utcIsoToDateTimeLocal(value: string | null | undefined): string {
  if (!value) return "";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "";
  const local = new Date(date.getTime() - date.getTimezoneOffset() * 60_000);
  return local.toISOString().slice(0, 16);
}

export function dateTimeLocalToUtcIso(value: string): string | null {
  if (!value) return null;
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return null;
  date.setSeconds(0, 0);
  return date.toISOString();
}

export function formatDateTime(value: string | null | undefined): string {
  if (!value) return "—";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "—";
  return new Intl.DateTimeFormat("ru-RU", { dateStyle: "medium", timeStyle: "short" }).format(date);
}

export function formatCertificateDate(value: string | null | undefined): string {
  if (!value) return "—";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "—";
  const hasTime = date.getHours() !== 0 || date.getMinutes() !== 0 || date.getSeconds() !== 0;
  return new Intl.DateTimeFormat("ru-RU", hasTime ? { dateStyle: "medium", timeStyle: "short" } : { dateStyle: "medium" }).format(date);
}

export function formatRelativeDateTime(value: string | null | undefined, now = new Date()): string {
  if (!value) return "—";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "—";
  const diffSeconds = Math.round((date.getTime() - now.getTime()) / 1000);
  const ranges: Array<{ limit: number; unit: Intl.RelativeTimeFormatUnit; seconds: number }> = [
    { limit: 60, unit: "second", seconds: 1 },
    { limit: 3600, unit: "minute", seconds: 60 },
    { limit: 86400, unit: "hour", seconds: 3600 },
    { limit: 2592000, unit: "day", seconds: 86400 },
    { limit: 31536000, unit: "month", seconds: 2592000 },
  ];
  const abs = Math.abs(diffSeconds);
  const range = ranges.find((item) => abs < item.limit) ?? { unit: "year", seconds: 31536000 };
  return new Intl.RelativeTimeFormat("ru-RU", { numeric: "auto" }).format(Math.round(diffSeconds / range.seconds), range.unit);
}

export function formatMoney(priceMinor: number, currency: string): string {
  return new Intl.NumberFormat("ru-RU", { style: "currency", currency }).format(priceMinor / 100);
}
