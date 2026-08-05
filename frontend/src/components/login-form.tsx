"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter, useSearchParams } from "next/navigation";
import { useState } from "react";
import { useForm } from "react-hook-form";
import { toast } from "sonner";
import { z } from "zod";
import { Button, FieldError, Input } from "@/components/ui";
import { applyProblemErrors, parseProblemResponse } from "@/lib/problem-details";
import { safeNext } from "@/lib/safe-next";

const schema = z.object({
  email: z.string().email("Введите email"),
  password: z.string().min(1, "Введите пароль"),
});

type FormValues = z.infer<typeof schema>;

export function LoginForm() {
  const router = useRouter();
  const params = useSearchParams();
  const [generalError, setGeneralError] = useState("");
  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { email: "admin@example.com", password: "Password123!" },
  });

  async function onSubmit(values: FormValues) {
    setGeneralError("");
    const response = await fetch("/api/auth/login", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(values),
    });
    if (!response.ok) {
      const problem = await parseProblemResponse(response);
      applyProblemErrors(problem, form.setError);
      setGeneralError(problem.status === 401 ? "Неверный email или пароль" : problem.detail);
      return;
    }
    toast.success("Вход выполнен");
    router.push(safeNext(params.get("next")));
    router.refresh();
  }

  return (
    <form className="mt-6 space-y-4" onSubmit={form.handleSubmit(onSubmit)}>
      <label className="block text-sm font-medium">
        Email
        <Input autoComplete="email" {...form.register("email")} />
        <FieldError message={form.formState.errors.email?.message} />
      </label>
      <label className="block text-sm font-medium">
        Пароль
        <Input type="password" autoComplete="current-password" {...form.register("password")} />
        <FieldError message={form.formState.errors.password?.message} />
      </label>
      {generalError && <p className="rounded-md bg-red-50 p-3 text-sm text-red-800">{generalError}</p>}
      <Button className="w-full" disabled={form.formState.isSubmitting}>
        {form.formState.isSubmitting ? "Входим..." : "Войти"}
      </Button>
    </form>
  );
}
