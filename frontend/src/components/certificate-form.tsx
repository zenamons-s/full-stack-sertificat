"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useQueryClient } from "@tanstack/react-query";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { useForm } from "react-hook-form";
import { toast } from "sonner";
import { z } from "zod";
import { StatusBadge } from "@/components/status-badge";
import { Button, FieldError, Input, SecondaryButton } from "@/components/ui";
import { bffFetch } from "@/lib/client-api";
import { dateTimeLocalToUtcIso, utcIsoToDateTimeLocal } from "@/lib/format";
import { ApiProblemError, applyProblemErrors } from "@/lib/problem-details";
import type { Certificate, CreateCertificateRequest, UpdateCertificateRequest } from "@/lib/types";

const baseSchema = z.object({
  title: z.string().trim().min(1, "Не должно быть пустым").max(255, "Не длиннее 255 символов"),
  price_minor: z.coerce.number().int("Введите целое число").positive("Должно быть больше нуля"),
  currency: z.string().trim().length(3, "Ровно 3 символа").transform((value) => value.toUpperCase()),
  expires_at: z.string().refine((value) => {
    const iso = dateTimeLocalToUtcIso(value);
    return iso !== null && new Date(iso).getTime() > Date.now();
  }, "Должно быть датой в будущем"),
});

const createSchema = baseSchema;
const updateSchema = baseSchema.extend({
  version: z.coerce.number().int("Введите целое число").positive("Должно быть больше нуля"),
});

type FormValues = z.infer<typeof baseSchema> & { version?: number };

type Props = {
  certificate?: Certificate;
};

export function CertificateForm({ certificate }: Props) {
  const router = useRouter();
  const queryClient = useQueryClient();
  const [generalError, setGeneralError] = useState("");
  const [conflict, setConflict] = useState<Certificate | null>(null);
  const form = useForm<FormValues>({
    resolver: zodResolver(certificate ? updateSchema : createSchema),
    defaultValues: {
      title: certificate?.title ?? "",
      price_minor: certificate?.price_minor ?? 10000,
      currency: certificate?.currency ?? "RUB",
      expires_at: utcIsoToDateTimeLocal(certificate?.expires_at),
      version: certificate?.version,
    },
  });

  async function onSubmit(values: FormValues) {
    setGeneralError("");
    setConflict(null);
    const expiresAt = dateTimeLocalToUtcIso(values.expires_at);
    if (!expiresAt) {
      form.setError("expires_at", { message: "Должно быть датой" });
      return;
    }
    try {
      if (certificate) {
        if (!values.version) {
          form.setError("version", { message: "Версия обязательна" });
          return;
        }
        const body: UpdateCertificateRequest = {
          title: values.title.trim(),
          price_minor: values.price_minor,
          currency: values.currency.toUpperCase(),
          expires_at: expiresAt,
          version: values.version,
        };
        const updated = await bffFetch<Certificate>(`/api/certificates/${certificate.id}`, { method: "PATCH", body: JSON.stringify(body) });
        form.setValue("version", updated.version);
        toast.success("Сертификат обновлён");
      } else {
        const body: CreateCertificateRequest = {
          title: values.title.trim(),
          price_minor: values.price_minor,
          currency: values.currency.toUpperCase(),
          expires_at: expiresAt,
        };
        await bffFetch<Certificate>("/api/certificates", { method: "POST", body: JSON.stringify(body) });
        toast.success("Сертификат создан");
        router.push("/certificates");
      }
      await queryClient.invalidateQueries({ queryKey: ["certificates"] });
      router.refresh();
    } catch (error) {
      if (error instanceof ApiProblemError) {
        applyProblemErrors(error.problem, form.setError);
        if (error.problem.status === 409 && error.problem.current_state) {
          setConflict(error.problem.current_state);
          setGeneralError("Запись изменилась после открытия формы. Введённые данные сохранены в форме.");
          return;
        }
        setGeneralError(error.problem.detail);
        return;
      }
      setGeneralError("Не удалось сохранить сертификат");
    }
  }

  function acceptConflictVersion() {
    if (!conflict) return;
    form.setValue("version", conflict.version);
    setConflict(null);
    toast.info("Версия обновлена, введённые значения оставлены");
  }

  return (
    <form className="max-w-2xl space-y-5 rounded-md border bg-white p-5" onSubmit={form.handleSubmit(onSubmit)}>
      {certificate && (
        <div className="flex items-center gap-3 text-sm">
          <span className="text-slate-600">Текущий статус</span>
          <StatusBadge status={certificate.status} />
          <input type="hidden" {...form.register("version")} />
        </div>
      )}
      <label className="block text-sm font-medium">
        Название
        <Input {...form.register("title")} />
        <FieldError message={form.formState.errors.title?.message} />
      </label>
      <label className="block text-sm font-medium">
        Цена в минорных единицах
        <Input type="number" min={1} {...form.register("price_minor")} />
        <FieldError message={form.formState.errors.price_minor?.message} />
      </label>
      <label className="block text-sm font-medium">
        Валюта
        <Input maxLength={3} {...form.register("currency")} />
        <FieldError message={form.formState.errors.currency?.message} />
      </label>
      <label className="block text-sm font-medium">
        Действует до
        <Input type="datetime-local" {...form.register("expires_at")} />
        <FieldError message={form.formState.errors.expires_at?.message} />
      </label>
      {conflict && (
        <div className="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
          <p>Актуальная версия: {conflict.version}, статус: {conflict.status}.</p>
          {conflict.status === "expired" && <p>Срок действия истёк во время редактирования.</p>}
          <button className="mt-2 underline" type="button" onClick={acceptConflictVersion}>Использовать актуальную версию для повторной отправки</button>
        </div>
      )}
      {generalError && <p className="rounded-md bg-red-50 p-3 text-sm text-red-800">{generalError}</p>}
      <div className="flex gap-2">
        <Button disabled={form.formState.isSubmitting}>{form.formState.isSubmitting ? "Сохранение..." : "Сохранить"}</Button>
        <Link href="/certificates"><SecondaryButton type="button">Отмена</SecondaryButton></Link>
      </div>
    </form>
  );
}
