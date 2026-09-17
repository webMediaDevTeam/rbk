import { jsx, jsxs } from "react/jsx-runtime";
import { cn } from "../lib/utils";
function Logo({ className, ...props }) {
  return /* @__PURE__ */ jsxs(
    "svg",
    {
      id: "shadcn-admin-logo",
      viewBox: "0 0 24 24",
      xmlns: "http://www.w3.org/2000/svg",
      height: "24",
      width: "24",
      fill: "none",
      stroke: "currentColor",
      strokeWidth: "2",
      strokeLinecap: "round",
      strokeLinejoin: "round",
      className: cn("size-6", className),
      ...props,
      children: [
        /* @__PURE__ */ jsx("title", { children: "Shadcn-Admin" }),
        /* @__PURE__ */ jsx("path", { d: "M15 6v12a3 3 0 1 0 3-3H6a3 3 0 1 0 3 3V6a3 3 0 1 0-3 3h12a3 3 0 1 0-3-3" })
      ]
    }
  );
}
export {
  Logo
};
