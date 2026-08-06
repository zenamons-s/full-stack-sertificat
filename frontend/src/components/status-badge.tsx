import { clsx } from "clsx";
import { BadgeCheck, CheckCircle, Clock, XCircle, type LucideIcon } from "lucide-react";
import type { CertificateStatus } from "@/lib/types";

const labels: Record<CertificateStatus, string> = {
  active: "active",
  expired: "expired",
  redeemed: "redeemed",
  cancelled: "cancelled",
};

const colors: Record<CertificateStatus, string> = {
  active: "bg-green-50 text-green-800 ring-green-200",
  expired: "bg-slate-100 text-slate-700 ring-slate-300",
  redeemed: "bg-blue-50 text-blue-800 ring-blue-200",
  cancelled: "bg-red-50 text-red-800 ring-red-200",
};

const icons: Record<CertificateStatus, LucideIcon> = {
  active: CheckCircle,
  expired: Clock,
  redeemed: BadgeCheck,
  cancelled: XCircle,
};

export function StatusBadge({ status }: { status: CertificateStatus }) {
  const Icon = icons[status];

  return (
    <span className={clsx("inline-flex items-center gap-1.5 rounded px-2 py-1 text-xs font-medium ring-1 transition-colors duration-150", colors[status])}>
      <Icon aria-hidden="true" className="shrink-0" size={14} />
      {labels[status]}
    </span>
  );
}
