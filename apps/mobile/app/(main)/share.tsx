import { PlaceholderScreen } from '@/components/placeholder-screen';
import { useT } from '@/i18n';

export default function ShareScreen() {
  const t = useT();
  return <PlaceholderScreen title={t('share.title')} subtitle={t('share.subtitle')} />;
}
