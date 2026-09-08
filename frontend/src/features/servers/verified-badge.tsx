import { CircleCheckBig } from 'lucide-react'

import { Badge } from '@/components/ui/badge'

/**
 * A verified connection, wherever it is shown.
 *
 * One component rather than two copies: the list view and the detail
 * page each carried the same six hand-written colour classes, and the
 * comment on both said it had to match the other -- which a copy
 * cannot guarantee.
 */
export function VerifiedBadge({ label }: { label: string }) {
  return (
    <Badge variant="success" className="gap-1">
      <CircleCheckBig className="size-3 fill-current/20" />
      {label}
    </Badge>
  )
}
