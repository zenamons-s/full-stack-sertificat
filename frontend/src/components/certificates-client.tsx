"use client";

import * as AlertDialog from "@radix-ui/react-alert-dialog";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowDown, ArrowUp, ChevronsUpDown, Plus, RotateCcw, SearchX, Trash2 } from "lucide-react";
import Link from "next/link";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { useCallback, useEffect, useMemo, useState, useTransition } from "react";
import { toast } from "sonner";
import { SecondaryButton, Select } from "@/components/ui";
import { StatusBadge } from "@/components/status-badge";
import { bffFetch } from "@/lib/client-api";
import { formatCertificateDate, formatDateTime, formatRelativeDateTime } from "@/lib/format";
import { mergeListParams, normalizeListParams, toQueryString, type CertificateListParams } from "@/lib/list-params";
import type { Certificate, CertificateStatus, PaginatedCertificates } from "@/lib/types";

type Props = {
  initialData: PaginatedCertificates;
  initialParams: CertificateListParams;
  initialStatusCounts: StatusCounts;
};

type StatusCounts = Record<Extract<CertificateStatus, "active" | "expired" | "redeemed">, number>;
type SortField = "title" | "price_minor" | "expires_at";

const statusCounters: Array<{ status: keyof StatusCounts; label: string }> = [
  { status: "active", label: "Активные" },
  { status: "expired", label: "Истёкшие" },
  { status: "redeemed", label: "Погашенные" },
];

export function CertificatesClient({ initialData, initialParams, initialStatusCounts }: Props) {
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const queryClient = useQueryClient();
  const [isPending, startTransition] = useTransition();
  const currentParams = useMemo(() => normalizeListParams(Object.fromEntries(searchParams.entries())), [searchParams]);
  const [searchText, setSearchText] = useState(currentParams.search ?? "");
  const [deleteTarget, setDeleteTarget] = useState<Certificate | null>(null);

  const query = toQueryString(currentParams);
  const key = ["certificates", currentParams.search ?? "", currentParams.status ?? "", currentParams.trashed, currentParams.page, currentParams.per_page, currentParams.sort];
  const certificates = useQuery({
    queryKey: key,
    queryFn: () => bffFetch<PaginatedCertificates>(`/api/certificates${query ? `?${query}` : ""}`),
    initialData: JSON.stringify(currentParams) === JSON.stringify(initialParams) ? initialData : undefined,
    refetchInterval: 30000,
  });
  const statusCounts = useQuery({
    queryKey: ["certificate-status-counts"],
    queryFn: fetchStatusCounts,
    initialData: initialStatusCounts,
    refetchInterval: 30000,
  });

  const updateParams = useCallback((patch: Partial<CertificateListParams>) => {
    const next = mergeListParams(searchParams.entries(), patch);
    const qs = toQueryString(next);
    startTransition(() => router.replace(qs ? `${pathname}?${qs}` : pathname));
  }, [pathname, router, searchParams, startTransition]);

  useEffect(() => setSearchText(currentParams.search ?? ""), [currentParams.search]);
  useEffect(() => {
    if (searchText === (currentParams.search ?? "")) {
      return;
    }

    const handle = setTimeout(() => updateParams({ search: searchText || undefined, page: 1 }), 300);
    return () => clearTimeout(handle);
  }, [currentParams.search, searchText, updateParams]);

  async function remove() {
    if (!deleteTarget) return;
    await bffFetch<void>(`/api/certificates/${deleteTarget.id}`, { method: "DELETE" });
    toast.success("Сертификат удалён");
    setDeleteTarget(null);
    await queryClient.invalidateQueries({ queryKey: ["certificates"] });
  }

  async function restore(item: Certificate) {
    await bffFetch<Certificate>(`/api/certificates/${item.id}/restore`, { method: "POST" });
    toast.success("Сертификат восстановлен");
    await queryClient.invalidateQueries({ queryKey: ["certificates"] });
  }

  const data = certificates.data;
  const hasSearch = Boolean(currentParams.search);

  return (
    <section className="space-y-4">
      <div className="flex flex-col justify-between gap-3 rounded-md border bg-white p-4 sm:flex-row sm:items-center">
        <div>
          <h1 className="text-xl font-semibold">Сертификаты</h1>
          <p className="mt-1 text-sm text-slate-600">Всего: {data?.meta.total ?? 0}</p>
        </div>
        <Link className="inline-flex items-center gap-2 rounded-md bg-blue-600 px-3 py-2 text-sm font-medium text-white transition-colors duration-150 hover:bg-blue-700" href="/certificates/new"><Plus size={16} /> Создать</Link>
      </div>

      <div className="flex flex-wrap gap-2">
        {statusCounters.map((item) => {
          const active = currentParams.status === item.status;
          return (
            <button
              aria-pressed={active}
              className={`rounded-md border px-3 py-2 text-sm transition-colors duration-150 ${active ? "border-blue-600 bg-blue-50 text-blue-800 hover:bg-blue-100" : "border-slate-200 bg-white text-slate-700 hover:bg-slate-50"}`}
              key={item.status}
              onClick={() => updateParams({ status: active ? undefined : item.status, page: 1 })}
              title={active ? "Сбросить фильтр статуса" : undefined}
              type="button"
            >
              {item.label} <span className="ml-1 font-semibold tabular-nums">{statusCounts.data[item.status]}</span>
            </button>
          );
        })}
      </div>

      <div className="grid gap-3 rounded-md border bg-white p-3 md:grid-cols-4">
        <input className="rounded-md border px-3 py-2 text-sm md:col-span-2" placeholder="Поиск" value={searchText} onChange={(event) => setSearchText(event.target.value)} />
        <Select value={currentParams.trashed} onChange={(event) => updateParams({ trashed: event.target.value as CertificateListParams["trashed"], page: 1 })}>
          <option value="none">Без удалённых</option>
          <option value="with">Показать удалённые</option>
          <option value="only">Только удалённые</option>
        </Select>
        <Select value={String(currentParams.per_page)} onChange={(event) => updateParams({ per_page: Number(event.target.value), page: 1 })}>
          <option value="10">10</option>
          <option value="20">20</option>
          <option value="50">50</option>
        </Select>
      </div>

      {certificates.isError && <div className="rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-800">Не удалось загрузить список.</div>}
      {certificates.isFetching && !data && <div className="rounded-md border bg-white p-6 text-sm text-slate-500">Загрузка...</div>}

      {data && (
        <div className="overflow-x-auto rounded-md border bg-white">
          <table className="min-w-full text-left text-sm">
            <thead className="bg-slate-50 text-xs uppercase text-slate-500">
              <tr>
                <SortableHeader currentSort={currentParams.sort} field="title" label="Название" onSort={(sort) => updateParams({ sort })} />
                <SortableHeader align="right" currentSort={currentParams.sort} field="price_minor" label="Цена" onSort={(sort) => updateParams({ sort })} />
                <SortableHeader currentSort={currentParams.sort} field="expires_at" label="Истекает" onSort={(sort) => updateParams({ sort })} />
                <th className="px-3 py-2">Статус</th>
                <th className="px-3 py-2">Изменён</th>
                <th className="px-3 py-2">Действия</th>
              </tr>
            </thead>
            <tbody>
              {data.data.length === 0 ? (
                <tr className="border-t">
                  <td className="px-3 py-10 text-center" colSpan={6}>
                    <div className="flex flex-col items-center gap-2 text-slate-500">
                      <SearchX size={24} />
                      <p className="font-medium text-slate-700">Ничего не найдено</p>
                      <p className="text-sm">{hasSearch ? "По этому поисковому запросу сертификатов нет." : "Сертификатов пока нет."}</p>
                    </div>
                  </td>
                </tr>
              ) : data.data.map((item: Certificate) => (
                <tr className="cursor-default border-t transition-colors duration-150 hover:bg-slate-50" key={item.id}>
                  <td className="px-3 py-2 font-medium">{item.title}{item.deleted_at && <span className="ml-2 text-xs text-slate-500">удалён</span>}</td>
                  <td className="px-3 py-2 text-right tabular-nums">{item.price_formatted}</td>
                  <td className="px-3 py-2">{formatCertificateDate(item.expires_at)}</td>
                  <td className="px-3 py-2"><StatusBadge status={item.status} /></td>
                  <td className="px-3 py-2"><time dateTime={item.updated_at} title={formatDateTime(item.updated_at)}>{formatRelativeDateTime(item.updated_at)}</time></td>
                  <td className="flex gap-2 px-3 py-2">
                    <Link className="rounded border border-slate-300 px-2 py-1 text-xs transition-colors duration-150 hover:border-blue-300 hover:bg-blue-50 hover:text-blue-800" href={`/certificates/${item.id}/edit`}>Изменить</Link>
                    {item.deleted_at ? <button className="rounded border border-slate-300 px-2 py-1 text-xs transition-colors duration-150 hover:border-blue-300 hover:bg-blue-50 hover:text-blue-800" onClick={() => void restore(item)} title="Восстановить" type="button"><RotateCcw size={14} /></button> : <button className="rounded border border-slate-300 px-2 py-1 text-xs text-red-700 transition-colors duration-150 hover:border-red-300 hover:bg-red-50 hover:text-red-800" onClick={() => setDeleteTarget(item)} title="Удалить" type="button"><Trash2 size={14} /></button>}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {data && (
        <div className="flex items-center justify-between text-sm">
          <SecondaryButton disabled={currentParams.page <= 1 || isPending} onClick={() => updateParams({ page: currentParams.page - 1 })}>Назад</SecondaryButton>
          <span>Страница {data.meta.page} из {Math.max(data.meta.total_pages, 1)}</span>
          <SecondaryButton disabled={currentParams.page >= data.meta.total_pages || isPending} onClick={() => updateParams({ page: currentParams.page + 1 })}>Вперёд</SecondaryButton>
        </div>
      )}

      <AlertDialog.Root open={deleteTarget !== null} onOpenChange={(open) => !open && setDeleteTarget(null)}>
        <AlertDialog.Portal>
          <AlertDialog.Overlay className="fixed inset-0 bg-black/30" />
          <AlertDialog.Content className="fixed left-1/2 top-1/2 w-[min(92vw,420px)] -translate-x-1/2 -translate-y-1/2 rounded-md bg-white p-5 shadow-lg">
            <AlertDialog.Title className="font-semibold">Удалить сертификат?</AlertDialog.Title>
            <AlertDialog.Description className="mt-2 text-sm text-slate-600">Запись «{deleteTarget?.title}» будет перемещена в корзину.</AlertDialog.Description>
            <div className="mt-5 flex justify-end gap-2">
              <AlertDialog.Cancel asChild><SecondaryButton>Отмена</SecondaryButton></AlertDialog.Cancel>
              <AlertDialog.Action asChild><button className="rounded-md bg-red-600 px-3 py-2 text-sm text-white" onClick={() => void remove()}>Удалить</button></AlertDialog.Action>
            </div>
          </AlertDialog.Content>
        </AlertDialog.Portal>
      </AlertDialog.Root>
    </section>
  );
}

function SortableHeader({ align = "left", currentSort, field, label, onSort }: {
  align?: "left" | "right";
  currentSort: CertificateListParams["sort"];
  field: SortField;
  label: string;
  onSort: (sort: CertificateListParams["sort"]) => void;
}) {
  const active = currentSort === field || currentSort === `-${field}`;
  const descending = currentSort === `-${field}`;
  const next = active && !descending ? `-${field}` : field;
  const Icon = !active ? ChevronsUpDown : descending ? ArrowDown : ArrowUp;

  return (
    <th className={`px-3 py-2 ${align === "right" ? "text-right" : "text-left"}`}>
      <button
        className={`inline-flex items-center gap-1 ${align === "right" ? "justify-end" : "justify-start"} w-full transition-colors duration-150 hover:text-slate-900`}
        onClick={() => onSort(next as CertificateListParams["sort"])}
        type="button"
      >
        <span>{label}</span>
        <Icon size={14} />
      </button>
    </th>
  );
}

async function fetchStatusCounts(): Promise<StatusCounts> {
  const [active, expired, redeemed] = await Promise.all([
    fetchStatusTotal("active"),
    fetchStatusTotal("expired"),
    fetchStatusTotal("redeemed"),
  ]);

  return { active, expired, redeemed };
}

async function fetchStatusTotal(status: keyof StatusCounts): Promise<number> {
  const result = await bffFetch<PaginatedCertificates>(`/api/certificates?status=${status}&per_page=1`);
  return result.meta.total;
}
