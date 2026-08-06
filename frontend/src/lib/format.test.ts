import { describe, expect, it } from "vitest";
import { dateTimeLocalToUtcIso, formatDateTime, formatMoney, utcIsoToDateTimeLocal } from "@/lib/format";

describe("format helpers", () => {
  it("round-trips UTC ISO through datetime-local", () => {
    const iso = "2027-01-01T00:00:00.000Z";
    const local = utcIsoToDateTimeLocal(iso);
    expect(dateTimeLocalToUtcIso(local)).toBe(iso);
  });

  it("returns empty value for invalid date", () => {
    expect(utcIsoToDateTimeLocal("not a date")).toBe("");
    expect(dateTimeLocalToUtcIso("")).toBeNull();
    expect(formatDateTime("bad")).toBe("—");
  });

  it("formats money from minor units", () => {
    expect(formatMoney(150000, "RUB")).toContain("1 500");
  });

  it("formats valid dates for display", () => {
    expect(formatDateTime("2027-01-01T00:00:00Z")).not.toBe("—");
  });
});
