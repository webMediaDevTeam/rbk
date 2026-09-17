Here is the implementation of the **Settings** page using modular React components with **Tailwind CSS** and **Lucide React** icons, matching the layout in your image.

---

### 1. `SettingsHeader.jsx`

Displays the top section title and description divider.

```jsx
import React from 'react';

export const SettingsHeader = () => {
  return (
    <div className="border-b border-gray-200 pb-5">
      <h1 className="text-2xl font-bold tracking-tight text-gray-900">Settings</h1>
      <p className="text-sm text-gray-500 mt-1">
        Manage your account settings and set e-mail preferences.
      </p>
    </div>
  );
};

```

---

### 2. `SettingsSidebar.jsx`

Handles the vertical tab navigation on the left side.

```jsx
import React from 'react';
import { User, Wrench, Palette, Bell, Monitor } from 'lucide-react';

const NAV_ITEMS = [
  { id: 'profile', label: 'Profile', icon: User },
  { id: 'account', label: 'Account', icon: Wrench },
  { id: 'appearance', label: 'Appearance', icon: Palette },
  { id: 'notifications', label: 'Notifications', icon: Bell },
  { id: 'display', label: 'Display', icon: Monitor },
];

export const SettingsSidebar = ({ activeTab, setActiveTab }) => {
  return (
    <nav className="w-full lg:w-56 flex-shrink-0 space-y-1">
      {NAV_ITEMS.map((item) => {
        const Icon = item.icon;
        const isActive = activeTab === item.id;
        return (
          <button
            key={item.id}
            onClick={() => setActiveTab(item.id)}
            className={`w-full flex items-center gap-3 px-3 py-2 text-sm font-medium rounded-lg transition-colors ${
              isActive
                ? 'bg-gray-100 text-gray-900 font-semibold'
                : 'text-gray-700 hover:bg-gray-50 hover:text-gray-900'
            }`}
          >
            <Icon className="h-4 w-4 text-gray-600" />
            <span>{item.label}</span>
          </button>
        );
      })}
    </nav>
  );
};

```

---

### 3. `ProfileForm.jsx`

Contains the form elements for Username, Email, Bio, and URLs.

```jsx
import React, { useState } from 'react';
import { ChevronDown } from 'lucide-react';

export const ProfileForm = () => {
  const [urls, setUrls] = useState([
    'https://shadcn.com',
    'http://twitter.com/shadcn',
  ]);

  const addUrl = () => {
    setUrls([...urls, '']);
  };

  const handleUrlChange = (index, value) => {
    const updated = [...urls];
    updated[index] = value;
    setUrls(updated);
  };

  return (
    <div className="space-y-6 max-w-2xl">
      <div>
        <h2 className="text-lg font-semibold text-gray-900">Profile</h2>
        <p className="text-sm text-gray-500">
          This is how others will see you on the site.
        </p>
      </div>

      <div className="border-b border-gray-200"></div>

      <form onSubmit={(e) => e.preventDefault()} className="space-y-6">
        {/* Username */}
        <div className="space-y-2">
          <label className="block text-sm font-medium text-gray-900">
            Username
          </label>
          <input
            type="text"
            defaultValue="shadcn"
            className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-slate-900 focus:border-transparent"
          />
          <p className="text-xs text-gray-500">
            This is your public display name. It can be your real name or a pseudonym. You can
            only change this once every 30 days.
          </p>
        </div>

        {/* Email */}
        <div className="space-y-2">
          <label className="block text-sm font-medium text-gray-900">
            Email
          </label>
          <div className="relative">
            <select
              defaultValue=""
              className="w-full appearance-none px-3 py-2 border border-gray-300 rounded-lg text-sm text-gray-500 focus:outline-none focus:ring-2 focus:ring-slate-900 bg-white"
            >
              <option value="" disabled>
                Select a verified email to display
              </option>
              <option value="m@example.com">m@example.com</option>
              <option value="m@google.com">m@google.com</option>
            </select>
            <div className="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-gray-500">
              <ChevronDown className="h-4 w-4" />
            </div>
          </div>
          <p className="text-xs text-gray-500">
            You can manage verified email addresses in your email settings.
          </p>
        </div>

        {/* Bio */}
        <div className="space-y-2">
          <label className="block text-sm font-medium text-gray-900">
            Bio
          </label>
          <textarea
            rows={3}
            defaultValue="I own a computer."
            className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-slate-900 focus:border-transparent resize-none"
          />
          <p className="text-xs text-gray-500">
            You can @mention other users and organizations to link to them.
          </p>
        </div>

        {/* URLs */}
        <div className="space-y-3">
          <div>
            <label className="block text-sm font-medium text-gray-900">
              URLs
            </label>
            <p className="text-xs text-gray-500 mt-0.5">
              Add links to your website, blog, or social media profiles.
            </p>
          </div>

          {urls.map((url, index) => (
            <input
              key={index}
              type="text"
              value={url}
              onChange={(e) => handleUrlChange(index, e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-slate-900 focus:border-transparent"
            />
          ))}

          <button
            type="button"
            onClick={addUrl}
            className="px-3 py-1.5 border border-gray-300 rounded-lg text-xs font-medium text-gray-700 bg-white hover:bg-gray-50 shadow-sm transition-colors"
          >
            Add URL
          </button>
        </div>

        {/* Update Profile Button */}
        <div>
          <button
            type="submit"
            className="px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-lg hover:bg-slate-800 transition-colors shadow-sm"
          >
            Update profile
          </button>
        </div>
      </form>
    </div>
  );
};

```

---

### 4. Main Page: `Settings.jsx`

Combines all components together in a clean flex layout.

```jsx
import React, { useState } from 'react';
import { SettingsHeader } from './SettingsHeader';
import { SettingsSidebar } from './SettingsSidebar';
import { ProfileForm } from './ProfileForm';

export default function Settings() {
  const [activeTab, setActiveTab] = useState('profile');

  return (
    <div className="bg-white min-h-screen p-8 text-gray-900 font-sans antialiased">
      <div className="max-w-6xl mx-auto space-y-8">
        <SettingsHeader />

        <div className="flex flex-col lg:flex-row gap-10">
          <SettingsSidebar activeTab={activeTab} setActiveTab={setActiveTab} />

          <main className="flex-1">
            {activeTab === 'profile' && <ProfileForm />}
            {activeTab !== 'profile' && (
              <div className="text-gray-500 text-sm">
                Content for <span className="font-semibold capitalize">{activeTab}</span> settings page.
              </div>
            )}
          </main>
        </div>
      </div>
    </div>
  );
}

```