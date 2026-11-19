FROM node:22-slim
WORKDIR /app
COPY package*.json ./
RUN npm ci --omit=dev
COPY . .
ENV NODE_ENV=production DATABASE_FILE=/data/app.db PORT=3000
VOLUME /data
EXPOSE 3000
CMD ["node", "src/server.js"]
