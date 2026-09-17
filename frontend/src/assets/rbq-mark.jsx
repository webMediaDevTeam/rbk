import faviconUrl from "./brand-icons/rbq_favicon_inverted_white_bg.svg";

function RBQMark({ className, ...props }) {
  return (
    <img
      src={faviconUrl}
      alt="RBQ"
      className={className}
      width={16}
      height={16}
      {...props}
    />
  );
}

export { RBQMark };