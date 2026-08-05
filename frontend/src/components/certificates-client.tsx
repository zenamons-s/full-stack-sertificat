"use client";

import * as AlertDialog from "@radix-ui/react-alert-dialog";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Plus, RotateCcw, Trash2 } from "lucide-react";
import Link from "next/link";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { useCallback, useEffect, useMemo, useState, useTransition } from "react";
import { toast } from "sonner";
import { SecondaryButton, Select } from "@/components/ui";
import { StatusBadge } from "@/components/status-badge";
import { bffFetch } from "@/lib/client-api";
import { formatDateTime } from "@/lib/format";
import { normalizeListParams, toQueryString, type CertificateListParams } from "@/lib/list-params";
import type { Certificate, PaginatedCertificates } from "@/lib/types";

type Props = {
  initialData: PaginatedCertificates;
  initialParams: CertificateListParams;
};

export function CertificatesClient({ initialData, initialParams }: Props) {
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

  const updateParams = useCallback((patch: Partial<CertificateListParams>) => {
    const next = { ...currentParams, ...patch };
    const qs = toQueryString(next);
    startTransition(() => router.replace(qs ? `${pathname}?${qs}` : pathname));
  }, [currentParams, pathname, router, startTransition]);

  useEffect(() => setSearchText(currentParams.search ?? ""), [currentParams.search]);
  useEffect(() => {
    const handle = setTimeout(() => updateParams({ search: searchText || undefined, page: 1 }), 300);
    return () => clearTimeout(handle);
  }, [searchText, updateParams]);

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

  return (
    <section className="space-y-4">
      <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
        <div>
          <h1 className="text-xl font-semibold">Сертификаты</h1>
          <p className="text-sm text-slate-600">Всего: {data?.meta.total ?? 0}</p>
        </div>
        <Link className="inline-flex items-center gap-2 rounded-md bg-blue-600 px-3 py-2 text-sm font-medium text-white" href="/certificates/new"><Plus size={16} /> Создать</Link>
      </div>

      <div className="grid gap-3 rounded-md border bg-white p-3 md:grid-cols-6">
        <input className="rounded-md border px-3 py-2 text-sm md:col-span-2" placeholder="Поиск" value={searchText} onChange={(event) => setSearchText(event.target.value)} />
        <Select value={currentParams.status ?? ""} onChange={(event) => updateParams({ status: (event.target.value || undefined) as CertificateListParams["status"], page: 1 })}>
          <option value="">Все статусы</option>
          <option value="active">active</option>
          <option value="expired">expired</option>
          <option value="redeemed">redeemed</option>
          <option value="cancelled">cancelled</option>
        </Select>
        <Select value={currentParams.sort} onChange={(event) => updateParams({ sort: event.target.value as CertificateListParams["sort"], page: 1 })}>
          <option value="-created_at">Новые сначала</option>
          <option value="created_at">Старые сначала</option>
          <option value="expires_at">Истекают раньше</option>
          <option value="-expires_at">Истекают позже</option>
          <option value="title">Название А-Я</option>
          <option value="-title">Название Я-А</option>
          <option value="price_minor">Цена ↑</option>
          <option value="-price_minor">Цена ↓</option>
        </Select>
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
      {data && data.data.length === 0 && <div className="rounded-md border bg-white p-8 text-center text-sm text-slate-500">Ничего не найдено</div>}

      {data && data.data.length > 0 && (
        <div className="overflow-x-auto rounded-md border bg-white">
          <table className="min-w-full text-left text-sm">
            <thead className="bg-slate-50 text-xs uppercase text-slate-500">
              <tr><th className="px-3 py-2">Название</th><th className="px-3 py-2">Цена</th><th className="px-3 py-2">Истекает</th><th className="px-3 py-2">Статус</th><th className="px-3 py-2">Изменён</th><th className="px-3 py-2">Действия</th></tr>
            </thead>
            <tbody>
              {data.data.map((item: Certificate) => (
                <tr className="border-t" key={item.id}>
                  <td className="px-3 py-2 font-medium">{item.title}{item.deleted_at && <span className="ml-2 text-xs text-slate-500">удалён</span>}</td>
                  <td className="px-3 py-2">{item.price_formatted}</td>
                  <td className="px-3 py-2">{formatDateTime(item.expires_at)}</td>
                  <td className="px-3 py-2"><StatusBadge status={item.status} /></td>
                  <td className="px-3 py-2">{formatDateTime(item.updated_at)}</td>
                  <td className="flex gap-2 px-3 py-2">
                    <Link className="rounded border px-2 py-1 text-xs" href={`/certificates/${item.id}/edit`}>Изменить</Link>
                    {item.deleted_at ? <button className="rounded border px-2 py-1 text-xs" onClick={() => void restore(item)}><RotateCcw size={14} /></button> : <button className="rounded border px-2 py-1 text-xs text-red-700" onClick={() => setDeleteTarget(item)}><Trash2 size={14} /></button>}
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
