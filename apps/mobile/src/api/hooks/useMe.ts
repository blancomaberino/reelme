import { useQuery } from '@tanstack/react-query';

import { api } from '../client';
import { queryKeys } from '../keys';
import type { Me } from '../types';

export async function fetchMe(): Promise<Me> {
  const { data } = await api.get<{ data: { user: Me } }>('/me');
  return data.data.user;
}

export function useMe() {
  return useQuery({ queryKey: queryKeys.me, queryFn: fetchMe });
}
