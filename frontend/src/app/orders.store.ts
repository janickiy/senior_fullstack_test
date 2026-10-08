import { HttpErrorResponse } from '@angular/common/http';
import { DestroyRef, computed, inject, Injectable, signal, WritableSignal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { finalize, Observable, Subscription } from 'rxjs';
import { OrdersApi } from './orders-api';
import { ImportBatchResult, OrderPagination, OrderStatus, OrderView } from './orders.models';

@Injectable({ providedIn: 'root' })
export class OrdersStore {
  private readonly api = inject(OrdersApi);
  private readonly destroyRef = inject(DestroyRef);
  private listRequest?: Subscription;
  private listRequestId = 0;

  readonly pageSize = 10;
  readonly shopId = signal('1');
  readonly shopError = signal<string | null>(null);
  readonly status = signal<OrderStatus | ''>('');
  readonly page = signal(1);
  readonly orders = signal<OrderView[]>([]);
  readonly pagination = signal<OrderPagination>(this.emptyPagination());
  readonly listLoading = signal(false);
  readonly isImporting = signal(false);
  readonly importReport = signal<ImportBatchResult | null>(null);
  readonly listError = signal<string | null>(null);
  readonly importError = signal<string | null>(null);
  readonly importFileName = signal<string | null>(null);
  readonly isExporting = signal(false);
  readonly isTemplateLoading = signal(false);
  readonly downloadError = signal<string | null>(null);
  readonly notice = signal<string | null>(null);
  readonly hasOrders = computed(() => this.orders().length > 0);

  setShop(value: string): boolean {
    const shopId = value.trim();
    if (!/^[A-Za-z0-9_-]{1,64}$/.test(shopId)) {
      this.shopError.set(
        'ID магазина: от 1 до 64 латинских букв, цифр, дефисов или подчёркиваний.',
      );
      return false;
    }
    if (this.isImporting()) {
      this.shopError.set('Дождитесь завершения импорта, прежде чем менять магазин.');
      return false;
    }

    this.shopError.set(null);
    if (shopId === this.shopId()) {
      return true;
    }

    this.shopId.set(shopId);
    this.importReport.set(null);
    this.importError.set(null);
    this.importFileName.set(null);
    this.downloadError.set(null);
    this.notice.set(null);
    this.resetList();
    this.refresh();
    return true;
  }

  refresh(): void {
    const requestId = ++this.listRequestId;
    this.listRequest?.unsubscribe();
    this.listLoading.set(true);
    this.listError.set(null);

    this.listRequest = this.api
      .list(this.shopId(), this.status(), this.page(), this.pageSize)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => {
          if (requestId === this.listRequestId) {
            this.listLoading.set(false);
          }
        }),
      )
      .subscribe({
        next: (response) => {
          if (requestId !== this.listRequestId) {
            return;
          }
          const lastPage = Math.max(1, response.pagination.pages);
          if (this.page() > lastPage) {
            this.page.set(lastPage);
            this.refresh();
            return;
          }
          this.orders.set(response.items);
          this.pagination.set(response.pagination);
        },
        error: (error: unknown) => {
          if (requestId !== this.listRequestId) {
            return;
          }
          this.orders.set([]);
          this.pagination.set(this.emptyPagination());
          this.listError.set(
            this.errorMessage(error, 'Не удалось загрузить заказы. Попробуйте ещё раз.'),
          );
        },
      });
  }

  setStatus(status: OrderStatus | ''): void {
    if (status === this.status()) {
      return;
    }
    this.status.set(status);
    this.resetList();
    this.refresh();
  }

  goToPage(page: number): void {
    if (
      !Number.isInteger(page) ||
      page < 1 ||
      page > this.pagination().pages ||
      page === this.page()
    ) {
      return;
    }
    this.page.set(page);
    this.orders.set([]);
    this.refresh();
  }

  importExcel(file: File): void {
    if (this.isImporting()) {
      return;
    }
    this.importFileName.set(file.name);
    this.importReport.set(null);
    this.notice.set(null);
    if (!file.name.toLowerCase().endsWith('.xlsx')) {
      this.importError.set('Выберите файл Excel с расширением .xlsx.');
      return;
    }
    if (file.size > 10 * 1024 * 1024) {
      this.importError.set('Размер файла Excel не должен превышать 10 МБ.');
      return;
    }
    const shopId = this.shopId();
    this.isImporting.set(true);
    this.importError.set(null);

    this.api
      .importExcel(shopId, file)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.isImporting.set(false)),
      )
      .subscribe({
        next: (report) => {
          if (shopId !== this.shopId()) {
            return;
          }
          this.importReport.set(report);
          this.notice.set(`Импорт завершён: обработано ${report.summary.total} заказов.`);
          this.resetList();
          this.refresh();
        },
        error: (error: unknown) => {
          const fallback =
            'Не удалось получить результат импорта. Можно повторить — заказы не продублируются.';
          this.importError.set(this.errorMessage(error, fallback));
        },
      });
  }

  exportExcel(): void {
    this.download(
      this.api.exportExcel(this.shopId()),
      `orders-shop-${this.shopId()}.xlsx`,
      this.isExporting,
      'Не удалось выгрузить заказы в Excel. Попробуйте ещё раз.',
    );
  }

  downloadTemplate(): void {
    this.download(
      this.api.templateExcel(this.shopId()),
      'orders-template.xlsx',
      this.isTemplateLoading,
      'Не удалось скачать шаблон Excel. Попробуйте ещё раз.',
    );
  }

  private download(
    request: Observable<Blob>,
    filename: string,
    loading: WritableSignal<boolean>,
    fallback: string,
  ): void {
    if (loading() || this.isImporting()) {
      return;
    }
    const shopId = this.shopId();
    loading.set(true);
    this.downloadError.set(null);
    request
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => loading.set(false)),
      )
      .subscribe({
        next: (blob) => {
          const url = URL.createObjectURL(blob);
          const link = document.createElement('a');
          link.href = url;
          link.download = filename;
          document.body.append(link);
          link.click();
          link.remove();
          setTimeout(() => URL.revokeObjectURL(url), 1000);
        },
        error: (error: unknown) => {
          if (shopId === this.shopId()) {
            this.downloadError.set(this.errorMessage(error, fallback));
          }
        },
      });
  }

  private resetList(): void {
    this.page.set(1);
    this.orders.set([]);
    this.pagination.set(this.emptyPagination());
  }

  private emptyPagination(): OrderPagination {
    return { page: this.page(), limit: this.pageSize, total: 0, pages: 0 };
  }

  private errorMessage(error: unknown, fallback: string): string {
    if (error instanceof HttpErrorResponse && error.status >= 400 && error.status < 500) {
      const body: unknown = error.error;
      if (typeof body === 'object' && body !== null) {
        const detail = 'detail' in body ? body.detail : undefined;
        if (typeof detail === 'string' && detail.trim()) {
          return detail;
        }
      }
    }
    return fallback;
  }
}
