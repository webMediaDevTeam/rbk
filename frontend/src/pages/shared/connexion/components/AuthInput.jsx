export default function AuthInput({
  id,
  label,
  type = "text",
  placeholder,
  value,
  onChange,
  icon: Icon,
  action,
  error,
  inputClassName = "auth-input",
  required = false,
}) {
  const input = (
    <input
      id={id}
      type={type}
      className={inputClassName}
      placeholder={placeholder}
      value={value}
      onChange={onChange}
      required={required}
    />
  );

  return (
    <div className="auth-field">
      <label className="auth-field__label" htmlFor={id}>
        {label}
      </label>
      {Icon || action ? (
        <div className="auth-input-wrap">
          {Icon && <Icon className="auth-input-wrap__icon" size={20} />}
          {input}
          {action}
        </div>
      ) : (
        input
      )}
      {error && <p className="auth-field__error">{error}</p>}
    </div>
  );
}
