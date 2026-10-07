import { Observable } from 'rxjs';
import { ImportBatchResult, OrderPage, OrderStatus } from '../domain/orders.models';

export abstract class OrdersGateway {
  abstract list(
    shopId: string,
    status: OrderStatus | '',
    page: number,
    limit: number,
  ): Observable<OrderPage>;
  abstract importOrders(shopId: string): Observable<ImportBatchResult>;
}

export class OrdersRequestError extends Error {}
