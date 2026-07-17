import { useQuery } from '@tanstack/react-query';

import { api } from '../client';
import { queryKeys } from '../keys';

/** A card/bank/wallet offering discounts, with how many places carry it (T-079). */
export type PaymentCard = {
  card: string;
  count: number;
};

async function fetchPaymentCards(): Promise<PaymentCard[]> {
  const { data } = await api.get<{ data: PaymentCard[] }>('/places/payment-cards');
  return data.data;
}

/** Distinct discount cards for the map filter bar (T-079). */
export function usePaymentCards() {
  return useQuery({
    queryKey: queryKeys.paymentCards(),
    queryFn: fetchPaymentCards,
    staleTime: 10 * 60_000,
  });
}
