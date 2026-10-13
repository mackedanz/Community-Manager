# Discord-Bot des Community-Managers. Gebaut wird aus dem Ordner BOT:
#   docker build -t community-manager-bot BOT
FROM node:24-alpine

ENV NODE_ENV=production
WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --omit=dev && npm cache clean --force

COPY src ./src

# In der docker-compose.yml läuft der Bot als www-data (33), damit er das Bilder-Volume der App mitbenutzen kann
USER node
CMD ["node", "src/index.js"]
