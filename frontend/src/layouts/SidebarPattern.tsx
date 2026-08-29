import { Box } from '@mui/material'
import { brand } from '@/theme'

/**
 * The decorative field behind the sidebar.
 *
 * A pharmacy-and-chemistry motif spanning the full height of the panel:
 * molecular rings, capsules and pharmacy crosses, scattered from the brand
 * mark down to the footer.
 *
 * Three decisions keep it premium rather than busy:
 *
 *  1. **Clusters, never a chain.** Bonds only ever join motifs inside the same
 *     small cluster — nothing spans more than about 50 units. A bond line that
 *     runs the length of the panel reads as a snake whatever it is drawn from.
 *     Discrete clusters at varying size and weight read as a field of texture.
 *  2. **Weighted right.** Navigation labels occupy the left of the panel, so
 *     every motif sits right of them and a single horizontal gradient fades the
 *     whole drawing to nothing before it reaches the text. The pattern is
 *     visible at every height without ever sitting behind a label.
 *  3. **Right-anchored scaling.** `xMaxYMid slice` pins the drawing to the
 *     right edge, so on a tall window the crop falls on the empty left side
 *     rather than pushing motifs under the navigation.
 *
 * The gradient uses `userSpaceOnUse`, so it is one field across the whole
 * drawing rather than restarting inside every shape.
 *
 * Drawn rather than imported: no asset, no request, no CDN. Nothing animates,
 * so there is no reduced-motion case. Clipped by the panel, so it never moves
 * when the navigation scrolls.
 */

const VB_W = 260
const VB_H = 900

/** Points for a pointy-top hexagon — a benzene ring. */
function ring(cx: number, cy: number, r: number): string {
  return Array.from({ length: 6 }, (_, i) => {
    const a = (Math.PI / 180) * (60 * i - 30)
    return `${(cx + r * Math.cos(a)).toFixed(1)},${(cy + r * Math.sin(a)).toFixed(1)}`
  }).join(' ')
}

/**
 * Molecular rings in five clusters down the panel — a large ring paired with a
 * smaller one, then a gap. The gaps are what stop it reading as a chain.
 */
const RINGS = [
  { cx: 228, cy: 100, r: 12, o: 0.22, w: 1 },
  { cx: 200, cy: 142, r: 9, o: 0.17, w: 0.9 },
  { cx: 240, cy: 226, r: 15, o: 0.25, w: 1.1 },
  { cx: 208, cy: 262, r: 8, o: 0.16, w: 0.85 },
  { cx: 198, cy: 400, r: 12, o: 0.2, w: 1 },
  { cx: 232, cy: 440, r: 15, o: 0.24, w: 1.1 },
  { cx: 246, cy: 582, r: 13, o: 0.22, w: 1 },
  { cx: 202, cy: 740, r: 11, o: 0.18, w: 0.95 },
  { cx: 234, cy: 778, r: 15, o: 0.25, w: 1.1 },
  { cx: 216, cy: 866, r: 12, o: 0.21, w: 1 },
]

/**
 * Bonds. Every one is short and stays inside its own cluster — either joining
 * that cluster's two rings, or a free stub ending in a node.
 */
const BONDS: Array<[number, number, number, number]> = [
  [228, 100, 200, 142],
  [228, 100, 246, 76],
  [240, 226, 208, 262],
  [198, 400, 232, 440],
  [232, 440, 248, 468],
  [246, 582, 226, 610],
  [202, 740, 234, 778],
  [216, 866, 242, 846],
]

/** Capsules — a pharmacy read, placed in the gaps between ring clusters. */
const CAPSULES = [
  { x: 250, y: 172, w: 23, h: 9, rot: -30, o: 0.2 },
  { x: 198, y: 330, w: 21, h: 8, rot: 28, o: 0.17 },
  { x: 248, y: 510, w: 23, h: 9, rot: -20, o: 0.19 },
  { x: 200, y: 662, w: 20, h: 8, rot: 34, o: 0.16 },
  { x: 244, y: 824, w: 22, h: 9, rot: -26, o: 0.18 },
]

/** Pharmacy crosses. Large enough to read as a cross rather than a speck. */
const CROSSES = [
  { x: 208, y: 196, s: 14, o: 0.17 },
  { x: 244, y: 356, s: 12, o: 0.14 },
  { x: 196, y: 556, s: 13, o: 0.16 },
  { x: 250, y: 706, s: 11, o: 0.13 },
  { x: 198, y: 806, s: 12, o: 0.15 },
]

/** Nodes closing the free bond stubs, plus a few loose dots for texture. */
const NODES = [
  { cx: 246, cy: 76, r: 2, o: 0.28 },
  { cx: 248, cy: 468, r: 2.2, o: 0.26 },
  { cx: 226, cy: 610, r: 2.4, o: 0.28 },
  { cx: 242, cy: 846, r: 2, o: 0.24 },
  { cx: 256, cy: 318, r: 2, o: 0.18 },
  { cx: 192, cy: 520, r: 1.8, o: 0.16 },
  { cx: 252, cy: 690, r: 1.8, o: 0.17 },
]

export function SidebarPattern() {
  return (
    <Box
      aria-hidden
      className="pv-sidebar-pattern"
      sx={{
        position: 'absolute',
        inset: 0,
        pointerEvents: 'none',
        overflow: 'hidden',
        zIndex: 0,
      }}
    >
      {/* Ambient lift behind the brand mark. */}
      <Box
        sx={{
          position: 'absolute',
          top: '-6%',
          left: '-30%',
          width: 210,
          height: 210,
          borderRadius: '50%',
          background: `radial-gradient(circle, ${brand.accent}18 0%, transparent 68%)`,
        }}
      />

      {/* A soft pool low and right, lifting the foot of the panel. */}
      <Box
        sx={{
          position: 'absolute',
          right: '-30%',
          bottom: '-22%',
          width: '120%',
          paddingTop: '120%',
          borderRadius: '50%',
          background: `radial-gradient(closest-side, ${brand.accent}20, transparent 70%)`,
        }}
      />

      <Box
        component="svg"
        viewBox={`0 0 ${VB_W} ${VB_H}`}
        preserveAspectRatio="xMaxYMid slice"
        sx={{ position: 'absolute', inset: 0, width: '100%', height: '100%', display: 'block' }}
      >
        <defs>
          {/* One field across the whole drawing — not per-shape. Everything is
              gone before it reaches the navigation labels on the left. */}
          <linearGradient id="pv-motif" gradientUnits="userSpaceOnUse" x1={VB_W} y1="0" x2="96" y2="0">
            <stop offset="0%" stopColor={brand.accent} stopOpacity="1" />
            <stop offset="46%" stopColor={brand.accent} stopOpacity="0.62" />
            <stop offset="100%" stopColor={brand.accent} stopOpacity="0" />
          </linearGradient>

          <linearGradient id="pv-veil" x1="0" y1="1" x2="0" y2="0">
            <stop offset="0%" stopColor={brand.accent} stopOpacity="0.12" />
            <stop offset="100%" stopColor={brand.accent} stopOpacity="0" />
          </linearGradient>
        </defs>

        {/* Contour sweep at the foot. */}
        <path
          d="M-20 900 C 62 838, 132 858, 200 792 C 234 760, 250 730, 286 704 L286 900 Z"
          fill="url(#pv-veil)"
        />
        <g fill="none" stroke="url(#pv-motif)" strokeLinecap="round">
          <path d="M-26 890 C 56 828, 126 850, 194 784 C 228 752, 244 722, 280 696" strokeWidth="1.1" opacity="0.16" />
          <path d="M-26 862 C 60 800, 132 824, 200 758 C 234 726, 250 696, 286 670" strokeWidth="0.95" opacity="0.11" />
        </g>

        {/* Bonds sit under the rings. */}
        <g stroke="url(#pv-motif)" strokeWidth="0.9" opacity="0.16" strokeLinecap="round">
          {BONDS.map(([x1, y1, x2, y2]) => (
            <line key={`${x1}-${y1}-${x2}-${y2}`} x1={x1} y1={y1} x2={x2} y2={y2} />
          ))}
        </g>

        {/* Molecular rings. */}
        <g fill="none" stroke="url(#pv-motif)" strokeLinejoin="round">
          {RINGS.map((r) => (
            <polygon key={`${r.cx}-${r.cy}`} points={ring(r.cx, r.cy, r.r)} strokeWidth={r.w} opacity={r.o} />
          ))}
        </g>

        {/* Capsules. */}
        <g fill="none" stroke="url(#pv-motif)">
          {CAPSULES.map((c) => (
            <g key={`${c.x}-${c.y}`} transform={`rotate(${c.rot} ${c.x} ${c.y})`} opacity={c.o}>
              <rect x={c.x - c.w / 2} y={c.y - c.h / 2} width={c.w} height={c.h} rx={c.h / 2} strokeWidth="1" />
              <line x1={c.x} y1={c.y - c.h / 2} x2={c.x} y2={c.y + c.h / 2} strokeWidth="0.8" />
            </g>
          ))}
        </g>

        {/* Pharmacy crosses. */}
        <g fill="url(#pv-motif)">
          {CROSSES.map((c) => (
            <g key={`${c.x}-${c.y}`} opacity={c.o}>
              <rect x={c.x - c.s / 6} y={c.y - c.s / 2} width={c.s / 3} height={c.s} rx={c.s / 12} />
              <rect x={c.x - c.s / 2} y={c.y - c.s / 6} width={c.s} height={c.s / 3} rx={c.s / 12} />
            </g>
          ))}
        </g>

        {/* Nodes. */}
        <g fill="url(#pv-motif)">
          {NODES.map((n) => (
            <circle key={`${n.cx}-${n.cy}`} cx={n.cx} cy={n.cy} r={n.r} opacity={n.o} />
          ))}
        </g>
      </Box>
    </Box>
  )
}
