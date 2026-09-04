import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Check, Copy, Download } from 'lucide-react'

import { Button } from '@/components/ui/button'

export function BackupCodeList({ codes }: { codes: string[] }) {
  const { t } = useTranslation()
  const [copied, setCopied] = useState(false)

  const asText = codes.join('\n')

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(asText)
      setCopied(true)
      window.setTimeout(() => setCopied(false), 2000)
    } catch {
      // Clipboard access can be denied; the codes stay readable on screen.
    }
  }

  const download = () => {
    const url = URL.createObjectURL(new Blob([asText], { type: 'text/plain' }))
    const link = document.createElement('a')

    link.href = url
    link.download = 'zomboidcontrol-recovery-codes.txt'
    link.click()

    URL.revokeObjectURL(url)
  }

  return (
    <div className="space-y-3">
      <ul className="grid grid-cols-2 gap-2 rounded-md border p-3 font-mono text-sm">
        {codes.map((code) => (
          <li key={code}>{code}</li>
        ))}
      </ul>

      <div className="flex gap-2">
        <Button variant="outline" size="sm" onClick={() => void copy()}>
          {copied ? <Check className="size-4" /> : <Copy className="size-4" />}
          {t('common.copy')}
        </Button>

        <Button variant="outline" size="sm" onClick={download}>
          <Download className="size-4" />
          {t('common.download')}
        </Button>
      </div>
    </div>
  )
}
