import type { DeletionPreset, SuggestionFile, SuggestionItem } from '@/types'

export function useScore() {
  function calculateFileScore(file: SuggestionFile, preset: DeletionPreset): number {
    let score = 0
    const c = preset.criteria

    if (c.ratio.enabled) {
      const bestRatio = Math.max(...file.torrents.map((t) => t.ratio), 0)
      if (bestRatio < c.ratio.threshold) {
        const factor = 1 - bestRatio / c.ratio.threshold
        score += Math.round(c.ratio.weight * factor)
      }
    }

    if (c.seed_time.enabled) {
      const maxSeedDays = Math.max(...file.torrents.map((t) => t.seed_time_seconds / 86400), 0)
      if (maxSeedDays > c.seed_time.threshold_days) {
        const excess = (maxSeedDays - c.seed_time.threshold_days) / c.seed_time.threshold_days
        score += Math.round(c.seed_time.weight * Math.min(excess, 1))
      }
    }

    if (c.file_size.enabled) {
      const sizeGb = file.file_size_bytes / 1073741824
      if (sizeGb > c.file_size.threshold_gb) {
        const excess = (sizeGb - c.file_size.threshold_gb) / c.file_size.threshold_gb
        score += Math.round(c.file_size.weight * Math.min(excess, 1))
      }
    }

    if (c.orphan_qbit.enabled && file.torrents.length === 0) {
      score += c.orphan_qbit.weight
    }

    if (c.cross_seed.enabled && file.cross_seed_count > 1) {
      score += c.cross_seed.weight * (file.cross_seed_count - 1)
    }

    return Math.max(0, score)
  }

  function calculateItemScore(item: SuggestionItem, preset: DeletionPreset): number {
    // SuggestionItem already has a server-side score; use it directly
    // But allow client-side override for live preview
    return Math.max(0, ...item.files.map((f) => calculateFileScore(f, preset)))
  }

  function scoreColor(score: number): 'success' | 'warn' | 'danger' | 'secondary' {
    if (score >= 70) return 'danger'
    if (score >= 40) return 'warn'
    if (score >= 10) return 'success'
    return 'secondary'
  }

  function scoreEmoji(score: number): string {
    if (score >= 70) return '🔴'
    if (score >= 40) return '🟡'
    if (score >= 10) return '🟢'
    return '⚪'
  }

  return { calculateFileScore, calculateItemScore, scoreColor, scoreEmoji }
}
