export default function RememberRow({ checked, onChange }) {
  return (
    <div className="auth-row">
      <label className="auth-check">
        <input
          type="checkbox"
          className="auth-check__input"
          checked={checked}
          onChange={onChange}
        />
        <span>Se souvenir de moi</span>
      </label>
    </div>
  );
}
