declare interface RequestData {
    raw: string;
    method: string;
    uri: string;
    headers: {
      Host: string;
      "User-Agent": string;
      "x-forwarded-for"?: string;
      "x-forwarded-proto"?: string;
      "Content-Type"?: string;
    };
    body?: string;
    query: any[];
    post: any[];
    curl: string;
    plugin?: PluginData;
  }

  declare interface ResponseData {
    status: number;
    reason?: string;
    headers: {
      Server: string;
      "Content-Type": string;
    };
    body: string;
  }

    declare interface ExposeLog {
        id: string;
        performed_at: string;
        duration: number;
        subdomain: string;
        request: RequestData;
        response: ResponseData;
    }

    declare interface ListEntry {
        id: string;
        duration: number;
        request_method: string;
        request_uri: string;
        plugin_data: PluginData;
        status_code: number;
    }

  interface InternalDashboardPageData {
    subdomains: string[];
    user: ExposeUser;
    max_logs: number;
    local_url: string;
    auth_token?: string;
    platform_url?: string;
  }

  interface BannerData {
    message: string;
    cta_text: string;
    cta_url: string;
    cta_suffix: string;
    background_color: string;
    text_color: string;
    background_style?: string;
    text_style?: string;
  }

  interface ExposeUser {
    can_specify_subdomains: number;
  }

  interface PostValue {
    name: string
    value: string
  }

  interface ReplayRequest {
    uri: string
    method: string;
    headers: Record<string, string>
    body?: string;
  }

  interface PluginData {
    plugin: string
    uiLabel: string
    cliLabel: string
    details: Record<string, string>
  }
