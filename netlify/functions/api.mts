import type { Config, Context } from "@netlify/functions";
import { handleApiRequest } from "../../src/server/api.js";

export const config: Config = { path: "/api/*" };

export default async (request: Request, context: Context): Promise<Response> =>
    handleApiRequest(request, context);
