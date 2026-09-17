import { cn } from '@/lib/utils'

export default function Select({ className, ...props }) {
  return (
    <select
      className={cn(
        'flex h-9 w-full cursor-pointer rounded-lg border border-input bg-card px-3 py-1 text-sm shadow-sm transition-colors font-bold [&>option]:font-bold focus-visible:outline-none focus-visible:border-ring disabled:cursor-not-allowed disabled:opacity-50',
        className
      )}
      {...props}
    />
  )
}