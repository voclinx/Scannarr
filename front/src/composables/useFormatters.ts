export function useFormatters() {
  function formatSize(bytes: number | null | undefined): string {
    if (bytes == null || isNaN(bytes)) return '—'
    if (bytes === 0) return '0 B'
    const units = ['B', 'KB', 'MB', 'GB', 'TB']
    const k = 1024
    const i = Math.floor(Math.log(bytes) / Math.log(k))
    return (bytes / Math.pow(k, i)).toFixed(i > 1 ? 1 : 0) + ' ' + units[i]
  }

  function formatSeedTime(seconds: number): string {
    if (seconds < 3600) return `${Math.floor(seconds / 60)}min`
    if (seconds < 86400) return `${Math.floor(seconds / 3600)}h`
    if (seconds < 86400 * 365) return `${Math.floor(seconds / 86400)}j`
    return `${(seconds / (86400 * 365)).toFixed(1)}a`
  }

  function formatRatio(ratio: number): string {
    return ratio.toFixed(2)
  }

  function ratioSeverity(ratio: number): 'success' | 'warn' | 'danger' {
    if (ratio >= 1.0) return 'success'
    if (ratio >= 0.5) return 'warn'
    return 'danger'
  }

  function seedingStatusLabel(status?: string): string {
    const labels: Record<string, string> = {
      seeding: 'Seeding',
      orphan: 'Orphelin',
      mixed: 'Mixte',
    }
    return labels[status ?? ''] ?? '—'
  }

  function seedingStatusSeverity(status?: string): 'success' | 'warn' | 'danger' | 'secondary' {
    if (status === 'seeding') return 'success'
    if (status === 'mixed') return 'warn'
    if (status === 'orphan') return 'danger'
    return 'secondary'
  }

  function formatRuntime(minutes: number): string {
    const h = Math.floor(minutes / 60)
    const m = minutes % 60
    return h > 0 ? `${h}h${m > 0 ? ` ${m}min` : ''}` : `${m}min`
  }

  return {
    formatSize,
    formatSeedTime,
    formatRatio,
    ratioSeverity,
    seedingStatusLabel,
    seedingStatusSeverity,
    formatRuntime,
  }
}
