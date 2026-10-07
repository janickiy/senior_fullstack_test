import { DestroyRef, computed, inject, Injectable, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { finalize, Subscription } from 'rxjs';
import { OrdersGateway, OrdersRequestError } from './orders-gateway';
import {
  ImportBatchResult,
  OrderPagination,
  OrderStatus,
  OrderView,
} from '../domain/orders.models';

@Injectable({ providedIn: 'root' })
export class OrdersStore {
  private readonly api = inject(OrdersGateway);
  private readonly destroyRef = inject(DestroyRef);
  private listRequest?: Subscription;
  private listRequestId = 0;

  readonly pageSize = 10;
  private readonly state = {
    shopId: signal('1'),
    shopError: signal<string | null>(null),
    status: signal<OrderStatus | ''>(''),
    page: signal(1),
    orders: signal<OrderView[]>([]),
    pagination: signal<OrderPagination>({ page: 1, limit: this.pageSize, total: 0, pages: 0 }),
    listLoading: signal(false),
    isImporting: signal(false),
    importReport: signal<ImportBatchResult | null>(null),
    listError: signal<string | null>(null),
    importError: signal<string | null>(null),
    notice: signal<string | null>(null),
  };
  readonly shopId = this.state.shopId.asReadonly();
  readonly shopError = this.state.shopError.asReadonly();
  readonly status = this.state.status.asReadonly();
  readonly page = this.state.page.asReadonly();
  readonly orders = this.state.orders.asReadonly();
  readonly pagination = this.state.pagination.asReadonly();
  readonly listLoading = this.state.listLoading.asReadonly();
  readonly isImporting = this.state.isImporting.asReadonly();
  readonly importReport = this.state.importReport.asReadonly();
  readonly listError = this.state.listError.asReadonly();
  readonly importError = this.state.importError.asReadonly();
  readonly notice = this.state.notice.asReadonly();
  readonly hasOrders = computed(() => this.orders().length > 0);

  setShop(value: string): boolean {
    const shopId = value.trim();
    if (!/^[A-Za-z0-9_-]{1,64}$/.test(shopId)) {
      this.state.shopError.set(
        'ID магазина: от 1 до 64 латинских букв, цифр, дефисов или подчёркиваний.',
      );
      return false;
    }
    if (this.isImporting()) {
      this.state.shopError.set('Дождитесь завершения импорта, прежде чем менять магазин.');
      return false;
    }

    this.state.shopError.set(null);
    if (shopId === this.shopId()) {
      return true;
    }

    this.state.shopId.set(shopId);
    this.state.importReport.set(null);
    this.state.importError.set(null);
    this.state.notice.set(null);
    this.resetList();
    this.refresh();
    return true;
  }

  refresh(): void {
    const requestId = ++this.listRequestId;
    this.listRequest?.unsubscribe();
    this.state.listLoading.set(true);
    this.state.listError.set(null);

    this.listRequest = this.api
      .list(this.shopId(), this.status(), this.page(), this.pageSize)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => {
          if (requestId === this.listRequestId) {
            this.state.listLoading.set(false);
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
            this.state.page.set(lastPage);
            this.refresh();
            return;
          }
          this.state.orders.set(response.items);
          this.state.pagination.set(response.pagination);
        },
        error: (error: unknown) => {
          if (requestId !== this.listRequestId) {
            return;
          }
          this.state.orders.set([]);
          this.state.pagination.set(this.emptyPagination());
          this.state.listError.set(
            this.errorMessage(error, 'Не удалось загрузить заказы. Попробуйте ещё раз.'),
          );
        },
      });
  }

  setStatus(status: OrderStatus | ''): void {
    if (status === this.status()) {
      return;
    }
    this.state.status.set(status);
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
    this.state.page.set(page);
    this.state.orders.set([]);
    this.refresh();
  }

  importOrders(): void {
    if (this.isImporting()) {
      return;
    }
    const shopId = this.shopId();
    this.state.isImporting.set(true);
    this.state.importReport.set(null);
    this.state.importError.set(null);
    this.state.notice.set(null);

    this.api
      .importOrders(shopId)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.state.isImporting.set(false)),
      )
      .subscribe({
        next: (report) => {
          if (shopId !== this.shopId()) {
            return;
          }
          this.state.importReport.set(report);
          this.state.notice.set(`Импорт завершён: обработано ${report.summary.total} заказов.`);
          this.resetList();
          this.refresh();
        },
        error: (error: unknown) => {
          const fallback =
            'Не удалось получить результат импорта. Можно повторить — заказы не продублируются.';
          this.state.importError.set(this.errorMessage(error, fallback));
        },
      });
  }

  private resetList(): void {
    this.state.page.set(1);
    this.state.orders.set([]);
    this.state.pagination.set(this.emptyPagination());
  }

  private emptyPagination(): OrderPagination {
    return { page: this.page(), limit: this.pageSize, total: 0, pages: 0 };
  }

  private errorMessage(error: unknown, fallback: string): string {
    return error instanceof OrdersRequestError && error.message ? error.message : fallback;
  }
}
