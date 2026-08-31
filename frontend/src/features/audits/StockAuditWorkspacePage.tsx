import { Box, Tab, Tabs } from '@mui/material'
import { type ReactNode, useState } from 'react'
import { AuditsPage } from './AuditsPage'
import { ScanCountTab } from './ScanCountTab'

/**
 * Keeps every tab mounted, only toggling which one is painted.
 *
 * The Scan & Count tab holds an audit in progress: which shop, what has been
 * scanned, a half-typed quantity. Unmounting it because someone flipped to
 * Audit Review to check something would throw that away for no reason, so
 * visibility is a CSS attribute rather than a mount decision. Audit Review
 * benefits the same way — its filters, sort and page stay put.
 */
function TabPanel({ value, index, children }: { value: number; index: number; children: ReactNode }) {
  return (
    <Box role="tabpanel" hidden={value !== index} id={`stock-audit-tabpanel-${index}`} aria-labelledby={`stock-audit-tab-${index}`}>
      {children}
    </Box>
  )
}

/**
 * The one screen a shop count now goes through.
 *
 * Scan & Count is the new, direct way in: counted live from the browser, one
 * barcode at a time. Audit Review is the existing screen, unchanged — every
 * audit lands there regardless of which route produced it, handheld, Excel or
 * this one.
 */
export function StockAuditWorkspacePage() {
  const [tab, setTab] = useState(0)

  return (
    <Box>
      <Box sx={{ borderBottom: 1, borderColor: 'divider', mb: 2.5 }}>
        <Tabs value={tab} onChange={(_, next: number) => setTab(next)} aria-label="Stock audit workspace">
          <Tab label="Scan & Count" id="stock-audit-tab-0" aria-controls="stock-audit-tabpanel-0" />
          <Tab label="Audit Review" id="stock-audit-tab-1" aria-controls="stock-audit-tabpanel-1" />
        </Tabs>
      </Box>

      <TabPanel value={tab} index={0}>
        <ScanCountTab />
      </TabPanel>

      <TabPanel value={tab} index={1}>
        <AuditsPage />
      </TabPanel>
    </Box>
  )
}
