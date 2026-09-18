import * as React from "react";
import { OTPInput, OTPInputContext } from "input-otp";
import { MinusIcon } from "lucide-react";
import { cn } from "@/lib/utils";
function InputOTP({
  className,
  containerClassName,
  ...props
}) {
  return <OTPInput
    data-slot='input-otp'
    containerClassName={cn(
      "flex items-center gap-2 has-disabled:opacity-50",
      containerClassName
    )}
    className={cn("disabled:cursor-not-allowed", className)}
    {...props}
  />;
}
function InputOTPGroup({ className, ...props }) {
  return <div
    data-slot='input-otp-group'
    className={cn("flex items-center", className)}
    {...props}
  />;
}
function InputOTPSlot({
  index,
  className,
  ...props
}) {
  const inputOTPContext = React.useContext(OTPInputContext);
  const { char, hasFakeCaret, isActive } = inputOTPContext?.slots[index] ?? {};
  return <div
    data-slot='input-otp-slot'
    data-active={isActive}
    className={cn(
      "relative flex h-12 w-11 items-center justify-center rounded-xl border-2 border-input bg-card font-mono text-lg font-semibold text-foreground shadow-xs transition-all duration-200 ease-out outline-none select-none",
      "hover:border-ring/60",
      "aria-invalid:border-destructive data-[active=true]:aria-invalid:border-destructive",
      "data-[active=true]:z-10 data-[active=true]:scale-105 data-[active=true]:border-primary data-[active=true]:bg-primary/5 data-[active=true]:ring-4 data-[active=true]:ring-primary/15 data-[active=true]:shadow-[0_8px_24px_-10px_var(--primary)]",
      "dark:bg-input/30",
      className
    )}
    {...props}
  >
      {char}
      {hasFakeCaret && <div className='pointer-events-none absolute inset-0 flex items-center justify-center'>
          <div className='h-5 w-px animate-caret-blink bg-primary duration-1000' />
        </div>}
    </div>;
}
function InputOTPSeparator({ ...props }) {
  return <div data-slot='input-otp-separator' role='separator' {...props}>
      <MinusIcon />
    </div>;
}
export {
  InputOTP,
  InputOTPGroup,
  InputOTPSeparator,
  InputOTPSlot
};
