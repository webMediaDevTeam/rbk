# ============================================================
# Stage 1 — Builder : compilation du bundle Vite de production
# ============================================================
# NOTE : le document prévoit node:18-alpine, mais Vite 8 / React 19
# exigent Node ^20.19.0 || >=22.12.0 (cf. package-lock.json) :
# node:18-alpine ferait échouer `npm ci` / `npm run build`.
FROM node:22-alpine AS builder

WORKDIR /app

# Vite fige ces variables à la compilation : elles doivent être
# disponibles AVANT `npm run build` (passées en build-arg par compose).
ARG VITE_API_URL=http://localhost:8000
ENV VITE_API_URL=${VITE_API_URL}

# Dépendances d'abord (cache Docker conservé tant que le lock ne change pas)
COPY package.json package-lock.json ./
RUN npm ci

COPY . .
RUN npm run build

# ============================================================
# Stage 2 — Development (cible optionnelle : stack de dev)
# ============================================================
FROM node:22-alpine AS development

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY . .

EXPOSE 5173
CMD ["npm", "run", "dev", "--", "--host", "0.0.0.0", "--port", "5173"]

# ============================================================
# Stage 3 — Production : Nginx sert les assets statiques
# ============================================================
FROM nginx:alpine AS production

# Config custom : fallback SPA (try_files ... /index.html)
COPY nginx.conf /etc/nginx/conf.d/default.conf

# Bundle compilé par le stage builder
COPY --from=builder /app/dist /usr/share/nginx/html

EXPOSE 80

CMD ["nginx", "-g", "daemon off;"]
