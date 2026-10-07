import { HttpClient, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { Observable, switchMap } from 'rxjs';
import { ImportBatchResult, MarketplaceBatch, OrderPage, OrderStatus } from './orders.models';

@Injectable({ providedIn: 'root' })
export class OrdersApi {
  private readonly http = inject(HttpClient);

  list(
    shopId: string,
    status: OrderStatus | '',
    page: number,
    limit: number,
  ): Observable<OrderPage> {
    let params = new HttpParams().set('page', page).set('limit', limit);
    if (status) {
      params = params.set('status', status);
    }

    return this.http.get<OrderPage>(this.ordersUrl(shopId), { params });
  }

  importOrders(shopId: string): Observable<ImportBatchResult> {
    return this.http
      .get<MarketplaceBatch>('/assets/marketplace-orders.json')
      .pipe(
        switchMap((batch) =>
          this.http.post<ImportBatchResult>(`${this.ordersUrl(shopId)}/import`, batch),
        ),
      );
  }

  private ordersUrl(shopId: string): string {
    return `/api/shops/${encodeURIComponent(shopId)}/orders`;
  }
}
