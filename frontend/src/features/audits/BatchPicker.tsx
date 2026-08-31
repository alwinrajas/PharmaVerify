import { Box, Card, CardContent, List, ListItemButton, ListItemText, Typography } from '@mui/material'
import { formatDate, formatQuantity } from '@/utils/format'
import type { AuditLookupMatch } from '@/types'

/**
 * Shown when a scanned code resolves to more than one batch.
 *
 * Never picks one automatically: only the operator can see which box is
 * physically in their hand, so the choice is always theirs, made by pressing
 * one of these rows.
 */
export function BatchPicker({
  matches,
  onSelect,
}: {
  matches: AuditLookupMatch[]
  onSelect: (match: AuditLookupMatch) => void
}) {
  const sample = matches[0]

  return (
    <Card>
      <CardContent sx={{ p: 2.5 }}>
        <Typography variant="subtitle1" sx={{ mb: 0.5 }}>
          {matches.length} batches found for {sample.product_code}
        </Typography>
        <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
          {sample.description} is held here in more than one batch. Choose the one in hand — batch and expiry
          cannot be guessed for you.
        </Typography>

        <List disablePadding sx={{ border: 1, borderColor: 'divider', borderRadius: 2, overflow: 'hidden' }}>
          {matches.map((match, index) => (
            <ListItemButton
              key={match.item_stock_id}
              divider={index < matches.length - 1}
              onClick={() => onSelect(match)}
              sx={{ py: 1.5, display: 'flex', gap: 2, alignItems: 'center' }}
            >
              <ListItemText
                primary={`Batch ${match.batch || '—'}`}
                secondary={`Expiry ${formatDate(match.expiry_date)} · Shelf ${match.shelf_location ?? '—'}`}
              />
              <Box sx={{ textAlign: 'right', flexShrink: 0 }}>
                <Typography variant="caption" sx={{ display: 'block' }}>
                  System qty
                </Typography>
                <Typography variant="body2" sx={{ fontWeight: 700 }}>
                  {formatQuantity(match.system_qty)} {match.uom}
                </Typography>
              </Box>
            </ListItemButton>
          ))}
        </List>
      </CardContent>
    </Card>
  )
}
